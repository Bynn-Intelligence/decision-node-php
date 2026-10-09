<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\ServerError;
use Bynn\DecisionNode\Image;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;

/**
 * Turns a request's images into their wire forms: a URL and an upload id as
 * given; local bytes inline as base64 while the request's local images total
 * under the inline threshold, uploaded at or above it; bytes uploaded before
 * by their cached upload id, whatever their size.
 *
 * @internal
 */
final class Images
{
    private const LOOPBACK = ['localhost', '127.0.0.1', '::1'];

    /** The length of an upload id: upl_ and 32 hex digits. */
    private const UPLOAD_ID_CHARS = 36;

    /** @var array<string, PromiseInterface> uploads in flight by cache key, each resolving with its upload id */
    private array $inflight = [];

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock the current Unix time, the one upload URLs are judged by
     */
    public function __construct(
        private readonly Api $api,
        private readonly UploadCache $cache,
        public readonly int $inlineThreshold,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * The images' wire forms as wires() would send them, without uploading:
     * an image it would send by upload id is counted with an upload id's
     * length. For measuring a body before anything is sent.
     *
     * @param list<mixed> $images Image objects or wire maps, already checked
     *
     * @return list<mixed>
     */
    public function measureWires(array $images): array
    {
        $wires = [];
        $total = 0;
        $local = [];
        foreach ($images as $i => $image) {
            if (!$image instanceof Image) {
                $wires[$i] = $image;
            } elseif ($image->data === null) {
                $wires[$i] = $image->toArray();
            } else {
                $total += strlen($image->data);
                $local[$i] = $image;
            }
        }
        $byId = static fn (Image $image): array => ['id' => $image->id, 'upload_id' => str_repeat('u', self::UPLOAD_ID_CHARS)];
        foreach ($local as $i => $image) {
            $key = UploadCache::key((string) $image->data, (string) $image->mediaType);
            $known = $this->cache->get($key) !== null || isset($this->inflight[$key]);
            $wires[$i] = $known || $total >= $this->inlineThreshold ? $byId($image) : $image->toArray();
        }
        ksort($wires);

        return array_values($wires);
    }

    /**
     * @param list<mixed> $images Image objects or wire maps, already checked
     *
     * @return PromiseInterface resolves with [list<mixed> $wires, list<string> $placed]: the wire
     *                          forms in order, and the upload ids the client placed in them
     */
    public function wires(array $images): PromiseInterface
    {
        $wires = [];
        $placed = [];
        $pending = [];
        $shared = [];
        $total = 0;
        foreach ($images as $i => $image) {
            if (!$image instanceof Image) {
                $wires[$i] = $image;
                continue;
            }
            if ($image->data === null) {
                $wires[$i] = $image->toArray();
                continue;
            }
            $total += strlen($image->data);
            $key = UploadCache::key($image->data, (string) $image->mediaType);
            $hit = $this->cache->get($key);
            if ($hit !== null) {
                $wires[$i] = ['id' => $image->id, 'upload_id' => $hit];
                $placed[] = $hit;
            } elseif (isset($this->inflight[$key])) {
                // Another request is uploading the same bytes: share its upload.
                $shared[$i] = $this->inflight[$key];
            } else {
                $pending[$i] = $image;
            }
        }

        $upload = null;
        if ($pending !== [] && $total >= $this->inlineThreshold) {
            $upload = $this->upload(array_values($pending));
        } else {
            foreach ($pending as $i => $image) {
                $wires[$i] = $image->toArray();
            }
            $pending = [];
        }
        if ($upload === null && $shared === []) {
            ksort($wires);

            return Create::promiseFor([array_values($wires), $placed]);
        }

        return Utils::all(['shared' => Utils::all($shared), 'upload' => $upload ?? Create::promiseFor([])])->then(
            static function (array $done) use ($wires, $placed, $pending, $images): array {
                foreach ($done['shared'] as $i => $id) {
                    $wires[$i] = ['id' => $images[$i]->id, 'upload_id' => $id];
                    $placed[] = $id;
                }
                foreach (array_keys($pending) as $n => $i) {
                    $wires[$i] = ['id' => $pending[$i]->id, 'upload_id' => $done['upload'][$n]];
                    $placed[] = $done['upload'][$n];
                }
                ksort($wires);

                return [array_values($wires), $placed];
            },
        );
    }

    /**
     * Uploads every image that has no live cache entry and is not already
     * being uploaded: one presign for all of them, then one PUT each, side by
     * side. An upload in flight for the same bytes is shared, not repeated.
     *
     * @param list<Image> $images local images, already checked
     *
     * @return PromiseInterface resolves with list<string>, the upload id of each image in order
     */
    public function upload(array $images): PromiseInterface
    {
        $promises = [];
        $distinct = [];
        foreach ($images as $n => $image) {
            $key = UploadCache::key((string) $image->data, (string) $image->mediaType);
            $hit = $this->cache->get($key);
            if ($hit !== null) {
                $promises[$n] = Create::promiseFor($hit);
            } elseif (isset($this->inflight[$key])) {
                $promises[$n] = $this->inflight[$key];
            } else {
                $distinct[$key] ??= $image;
                $promises[$n] = $key;
            }
        }
        if ($distinct !== []) {
            $keys = array_keys($distinct);
            $batch = $this->presignAndPut($distinct)->then(
                function (array $fresh) use ($keys): array {
                    foreach ($keys as $key) {
                        unset($this->inflight[$key]);
                    }

                    return $fresh;
                },
                function (mixed $reason) use ($keys): never {
                    foreach ($keys as $key) {
                        unset($this->inflight[$key]);
                    }
                    throw $reason instanceof \Throwable ? $reason : new DecisionNodeError('The upload failed.');
                },
            );
            foreach ($keys as $key) {
                $this->inflight[$key] = $batch->then(static fn (array $fresh): string => $fresh[$key]);
            }
            foreach ($promises as $n => $promise) {
                if (is_string($promise)) {
                    $promises[$n] = $this->inflight[$promise];
                }
            }
        }

        /** @var array<int, PromiseInterface> $promises */
        return Utils::all($promises)->then(static function (array $ids): array {
            ksort($ids);

            return array_values($ids);
        });
    }

    /**
     * One presign for the images, the PUTs side by side, then the cache. A
     * PUT answered 403 once its URL's time has passed (by the client's clock)
     * is presigned again once; a 403 inside that time is a refusal.
     *
     * @param array<string, Image> $distinct by cache key
     *
     * @return PromiseInterface resolves with array<string, string>, the upload id by cache key
     */
    private function presignAndPut(array $distinct): PromiseInterface
    {
        return $this->presign($distinct)->then(function (array $presigned) use ($distinct): PromiseInterface {
            [$uploads, $urlsExpire] = $presigned;
            $puts = [];
            $n = 0;
            foreach ($distinct as $key => $image) {
                $upload = $uploads[$n++];
                $puts[$key] = $this->api->put($upload['url'], $upload['headers'], (string) $image->data)->then(
                    static fn (): array => $upload,
                    function (mixed $reason) use ($urlsExpire, $key, $image): PromiseInterface {
                        if ($reason instanceof DecisionNodeError && $reason->status === 403 && ($this->clock)() >= $urlsExpire) {
                            return $this->presign([$key => $image])->then(function (array $again) use ($image): PromiseInterface {
                                $upload = $again[0][0];

                                return $this->api->put($upload['url'], $upload['headers'], (string) $image->data)->then(static fn (): array => $upload);
                            });
                        }
                        throw $reason instanceof \Throwable ? $reason : new DecisionNodeError('The upload failed.');
                    },
                );
            }

            return Utils::all($puts)->then(function (array $done): array {
                $fresh = [];
                foreach ($done as $key => $upload) {
                    if ($upload['expires_at'] !== null) {
                        $this->cache->put((string) $key, $upload['upload_id'], $upload['expires_at']);
                    }
                    $fresh[(string) $key] = $upload['upload_id'];
                }

                return $fresh;
            });
        });
    }

    /**
     * `POST /v1/uploads` for the images.
     *
     * @param array<string, Image> $images
     *
     * @return PromiseInterface resolves with [the checked uploads in order, the time their URLs expire]
     */
    private function presign(array $images): PromiseInterface
    {
        $files = [];
        foreach ($images as $image) {
            $bytes = (string) $image->data;
            $files[] = [
                'content_type' => (string) $image->mediaType,
                'byte_size' => strlen($bytes),
                'checksum' => base64_encode(md5($bytes, true)),
                'sha256' => base64_encode(hash('sha256', $bytes, true)),
            ];
        }

        return $this->api->request('POST', '/v1/uploads', Json::encode(['files' => $files]))->then(
            function (array $result) use ($images): array {
                $body = $result['body'];
                $lifetime = is_array($body) && (is_int($body['expires_in'] ?? null) || is_float($body['expires_in'] ?? null)) ? (float) $body['expires_in'] : 300.0;

                return [self::uploads($result, count($images)), ($this->clock)() + $lifetime];
            },
        );
    }

    /**
     * Drops cache entries the API reported stale.
     *
     * @param list<string> $uploadIds
     */
    public function forget(array $uploadIds): void
    {
        $this->cache->drop($uploadIds);
    }

    /**
     * The presign answer's uploads, checked: one per file, each with an id, a
     * storage URL that is https (http only on this machine) and its headers.
     *
     * @param array<string, mixed> $result
     *
     * @return list<array{upload_id: string, url: string, headers: array<string, string>, expires_at: float|null}>
     */
    private static function uploads(array $result, int $count): array
    {
        $body = $result['body'];
        $list = is_array($body) && is_array($body['uploads'] ?? null) ? $body['uploads'] : null;
        /** @var array<string, string> $headers */
        $headers = $result['headers'];
        if ($list === null || count($list) !== $count) {
            throw new ServerError(
                'The upload answer does not hold one upload per file.',
                status: is_int($result['status']) ? $result['status'] : null,
                requestId: $headers['x-request-id'] ?? null,
                body: $body,
                headers: $headers,
            );
        }
        $out = [];
        foreach (array_values($list) as $upload) {
            if (!is_array($upload) || !is_string($upload['upload_id'] ?? null) || !is_string($upload['url'] ?? null)) {
                throw new ServerError('The upload answer is missing an upload id or URL.', status: is_int($result['status']) ? $result['status'] : null, requestId: $headers['x-request-id'] ?? null, body: $body, headers: $headers);
            }
            self::checkStorageUrl($upload['url']);
            $put = [];
            foreach (is_array($upload['headers'] ?? null) ? $upload['headers'] : [] as $name => $value) {
                if (is_scalar($value)) {
                    $put[(string) $name] = (string) $value;
                }
            }
            $out[] = [
                'upload_id' => $upload['upload_id'],
                'url' => $upload['url'],
                'headers' => $put,
                'expires_at' => self::time($upload['expires_at'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * A storage URL must be https, so the bytes never travel unencrypted;
     * plain http is accepted only for this machine.
     */
    private static function checkStorageUrl(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($host !== '' && ($scheme === 'https' || ($scheme === 'http' && in_array($host, self::LOOPBACK, true)))) {
            return;
        }

        throw new DecisionNodeError(sprintf('The storage URL must be https, got "%s". Nothing was sent to it.', $url), errorType: 'upload_failed');
    }

    private static function time(mixed $value): ?float
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return (float) (new \DateTimeImmutable($value))->format('U.u');
        } catch (\Exception) {
            return null;
        }
    }
}
