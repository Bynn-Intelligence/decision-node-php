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

    public function __construct(
        private readonly Api $api,
        private readonly UploadCache $cache,
        public readonly int $inlineThreshold,
    ) {
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
            $hit = $this->cache->get(UploadCache::key($image->data, (string) $image->mediaType));
            if ($hit !== null) {
                $wires[$i] = ['id' => $image->id, 'upload_id' => $hit];
                $placed[] = $hit;
                continue;
            }
            $pending[$i] = $image;
        }

        if ($pending === [] || $total < $this->inlineThreshold) {
            foreach ($pending as $i => $image) {
                $wires[$i] = $image->toArray();
            }
            ksort($wires);

            return Create::promiseFor([array_values($wires), $placed]);
        }

        return $this->upload(array_values($pending))->then(
            static function (array $ids) use ($wires, $placed, $pending): array {
                foreach (array_keys($pending) as $n => $i) {
                    $wires[$i] = ['id' => $pending[$i]->id, 'upload_id' => $ids[$n]];
                    $placed[] = $ids[$n];
                }
                ksort($wires);

                return [array_values($wires), $placed];
            },
        );
    }

    /**
     * Uploads every image that has no live cache entry: one presign for all of
     * them, then one PUT each, side by side.
     *
     * @param list<Image> $images local images, already checked
     *
     * @return PromiseInterface resolves with list<string>, the upload id of each image in order
     */
    public function upload(array $images): PromiseInterface
    {
        $ids = [];
        $distinct = [];
        foreach ($images as $n => $image) {
            $key = UploadCache::key((string) $image->data, (string) $image->mediaType);
            $hit = $this->cache->get($key);
            if ($hit !== null) {
                $ids[$n] = $hit;
            } else {
                $distinct[$key] ??= $image;
            }
        }
        if ($distinct === []) {
            return Create::promiseFor(self::ordered($images, $ids, []));
        }

        $files = [];
        foreach ($distinct as $image) {
            $bytes = (string) $image->data;
            $files[] = [
                'content_type' => (string) $image->mediaType,
                'byte_size' => strlen($bytes),
                'checksum' => base64_encode(md5($bytes, true)),
                'sha256' => base64_encode(hash('sha256', $bytes, true)),
            ];
        }

        return $this->api->request('POST', '/v1/uploads', Json::encode(['files' => $files]))->then(
            function (array $result) use ($distinct, $images, $ids): PromiseInterface {
                $uploads = self::uploads($result, count($distinct));
                $puts = [];
                $n = 0;
                foreach ($distinct as $image) {
                    $puts[] = $this->api->put($uploads[$n]['url'], $uploads[$n]['headers'], (string) $image->data);
                    ++$n;
                }

                return Utils::all($puts)->then(function () use ($distinct, $uploads, $images, $ids): array {
                    $fresh = [];
                    $n = 0;
                    foreach ($distinct as $key => $image) {
                        $upload = $uploads[$n++];
                        if ($upload['expires_at'] !== null) {
                            $this->cache->put($key, $upload['upload_id'], $upload['expires_at']);
                        }
                        $fresh[$key] = $upload['upload_id'];
                    }

                    return self::ordered($images, $ids, $fresh);
                });
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
     * @param list<Image>           $images
     * @param array<int, string>    $ids    cache hits by position
     * @param array<string, string> $fresh  new uploads by cache key
     *
     * @return list<string>
     */
    private static function ordered(array $images, array $ids, array $fresh): array
    {
        $out = [];
        foreach ($images as $n => $image) {
            $out[] = $ids[$n] ?? $fresh[UploadCache::key((string) $image->data, (string) $image->mediaType)];
        }

        return $out;
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
