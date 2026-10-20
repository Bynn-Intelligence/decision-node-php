<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

use Bynn\DecisionNode\Document;
use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * Turns a document into what an item call sends: text inline, or a file
 * uploaded with the knowledge purpose and named by its upload id. Nothing
 * about a document is cached: a knowledge upload is consumed by the item it
 * makes.
 *
 * @internal
 */
final class Documents
{
    /** Seconds one storage PUT of a document may take by default: a file is up to 50 MB. */
    public const DEFAULT_UPLOAD_TIMEOUT = 120.0;

    /** A file's type by its name's extension, lower case. */
    private const EXTENSIONS = [
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'markdown' => 'text/markdown',
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'html' => 'text/html',
        'htm' => 'text/html',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'webp' => 'image/webp',
    ];

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param float                    $putTimeout seconds per storage PUT attempt
     * @param (\Closure(): float)|null $clock      the current Unix time, the one upload URLs are judged by
     */
    public function __construct(
        private readonly Api $api,
        public readonly int $inlineThreshold,
        private readonly float $putTimeout,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * An item call's document, every local check made: `body` the document's
     * part of the body in the API's order (`title`, `text`, `format`, or
     * `upload_id`, `title`), and `file` the file to upload first, whose
     * upload id then goes in `body`, or null.
     *
     * @param array<array-key, mixed>|null $metadata
     *
     * @return array{body: array<string, mixed>, file: array{bytes: string, content_type: string, filename: string|null}|null}
     *
     * @throws BadRequestError|\Bynn\DecisionNode\Exception\ValidationError
     */
    public function plan(Document $document, ?string $title, ?string $reference, ?int $pinRank, ?string $label, ?array $metadata): array
    {
        if ($document->uploadId !== null) {
            LocalChecks::document($title, false, null, null, $reference, $pinRank, $label, $metadata);

            return ['body' => ['upload_id' => $document->uploadId] + ($title !== null ? ['title' => $title] : []), 'file' => null];
        }
        if ($document->text !== null) {
            LocalChecks::document($title, true, $document->text, $document->format, $reference, $pinRank, $label, $metadata);
            $body = ['title' => $title, 'text' => $document->text];
            if ($document->format !== null) {
                $body['format'] = $document->format;
            }

            return ['body' => $body, 'file' => null];
        }

        $file = self::file($document, 0);
        if (self::inline($file, $this->inlineThreshold)) {
            // A small UTF-8 text or Markdown file: one call instead of three.
            $title ??= self::stem($file['filename']);
            LocalChecks::document($title, true, null, null, $reference, $pinRank, $label, $metadata);

            return ['body' => [
                'title' => $title,
                'text' => $file['bytes'],
                'format' => $file['content_type'] === 'text/markdown' ? 'markdown' : 'text',
            ], 'file' => null];
        }
        LocalChecks::document($title, false, null, null, $reference, $pinRank, $label, $metadata);

        return ['body' => ['upload_id' => null] + ($title !== null ? ['title' => $title] : []), 'file' => $file];
    }

    /**
     * Whether a file goes inline: text or Markdown under the threshold that
     * is UTF-8 with no NUL.
     *
     * @param array{bytes: string, content_type: string, filename: string|null} $file
     */
    private static function inline(array $file, int $threshold): bool
    {
        return ($file['content_type'] === 'text/plain' || $file['content_type'] === 'text/markdown')
            && strlen($file['bytes']) < $threshold
            && !str_contains($file['bytes'], "\0")
            && mb_check_encoding($file['bytes'], 'UTF-8');
    }

    /**
     * A file document's bytes, type and name, checked as the presign checks
     * them: 1 byte to 50 MB, and one of the knowledge types. A text document
     * is a file of its text, `text/markdown` or `text/plain` by its format.
     *
     * @return array{bytes: string, content_type: string, filename: string|null}
     *
     * @throws BadRequestError|\InvalidArgumentException
     */
    public static function file(Document $document, int $index): array
    {
        if ($document->uploadId !== null) {
            throw new \InvalidArgumentException('A document uploaded before cannot be uploaded again: pass its file.');
        }
        if ($document->text !== null) {
            LocalChecks::document(null, false, null, $document->format, null, null, null, null);
            $bytes = $document->text;
            $type = $document->format === 'text' ? 'text/plain' : 'text/markdown';
        } else {
            $bytes = self::bytes($document, $index);
            $type = self::contentType($document, $bytes, $index);
        }
        $size = strlen($bytes);
        if ($size === 0) {
            throw self::bad(sprintf('files[%d].byte_size: must be an integer from 1 to %d.', $index, Document::MAX_FILE_BYTES));
        }
        if ($size > Document::MAX_FILE_BYTES) {
            throw self::tooLarge($index, $size);
        }

        return ['bytes' => $bytes, 'content_type' => $type, 'filename' => $document->filename];
    }

    /**
     * Uploads the files: one presign for all of them with the knowledge
     * purpose, then one PUT each, side by side. A PUT answered 403 once its
     * URL's time has passed is presigned again once.
     *
     * @param list<array{bytes: string, content_type: string, filename: string|null}> $files
     *
     * @return PromiseInterface resolves with list<string>, the upload id of each file in order
     */
    public function upload(array $files): PromiseInterface
    {
        return $this->presign($files)->then(function (array $presigned) use ($files): PromiseInterface {
            [$uploads, $urlsExpire] = $presigned;
            $puts = [];
            foreach ($files as $n => $file) {
                $upload = $uploads[$n];
                $puts[$n] = $this->api->put($upload['url'], $upload['headers'], $file['bytes'], $this->putTimeout)->then(
                    static fn (): string => $upload['upload_id'],
                    function (mixed $reason) use ($urlsExpire, $file): PromiseInterface {
                        if ($reason instanceof DecisionNodeError && $reason->status === 403 && ($this->clock)() >= $urlsExpire) {
                            return $this->presign([$file])->then(function (array $again) use ($file): PromiseInterface {
                                $upload = $again[0][0];

                                return $this->api->put($upload['url'], $upload['headers'], $file['bytes'], $this->putTimeout)
                                    ->then(static fn (): string => $upload['upload_id']);
                            });
                        }
                        throw $reason instanceof \Throwable ? $reason : new DecisionNodeError('The upload failed.');
                    },
                );
            }

            return Utils::all($puts)->then(static function (array $ids): array {
                ksort($ids);

                return array_values($ids);
            });
        });
    }

    /**
     * `POST /v1/uploads` for the files, each with `"purpose": "knowledge"`.
     *
     * @param list<array{bytes: string, content_type: string, filename: string|null}> $files
     *
     * @return PromiseInterface resolves with [the checked uploads in order, the time their URLs expire]
     */
    private function presign(array $files): PromiseInterface
    {
        $wire = [];
        foreach ($files as $file) {
            $entry = [
                'purpose' => 'knowledge',
                'content_type' => $file['content_type'],
                'byte_size' => strlen($file['bytes']),
                'checksum' => base64_encode(md5($file['bytes'], true)),
                'sha256' => base64_encode(hash('sha256', $file['bytes'], true)),
            ];
            if ($file['filename'] !== null) {
                $entry['filename'] = $file['filename'];
            }
            $wire[] = $entry;
        }

        return $this->api->request('POST', '/v1/uploads', Json::encode(['files' => $wire]))->then(
            function (array $result) use ($files): array {
                $body = $result['body'];
                $lifetime = is_array($body) && (is_int($body['expires_in'] ?? null) || is_float($body['expires_in'] ?? null)) ? (float) $body['expires_in'] : 300.0;

                return [Images::uploads($result, count($files)), ($this->clock)() + $lifetime];
            },
        );
    }

    /**
     * The file name's stem (the name without its last extension), or null
     * when there is no name or the stem is empty.
     */
    private static function stem(?string $filename): ?string
    {
        if ($filename === null) {
            return null;
        }
        $dot = strrpos($filename, '.');
        $stem = $dot === false ? $filename : substr($filename, 0, $dot);

        return $stem === '' ? null : $stem;
    }

    /**
     * The document's bytes: a path read now (its size checked first), or a
     * stream read to its end, stopping past 50 MB.
     */
    private static function bytes(Document $document, int $index): string
    {
        if ($document->data !== null) {
            return $document->data;
        }
        if ($document->path !== null) {
            $path = $document->path;
            if (!is_file($path) || !is_readable($path)) {
                throw new \InvalidArgumentException(sprintf('Document: cannot read %s.', $path));
            }
            $size = @filesize($path);
            if (is_int($size) && $size > Document::MAX_FILE_BYTES) {
                throw self::tooLarge($index, $size);
            }
            $data = @file_get_contents($path, false, null, 0, Document::MAX_FILE_BYTES + 1);
            if ($data === false) {
                throw new \InvalidArgumentException(sprintf('Document: cannot read %s.', $path));
            }

            return $data;
        }
        $stream = $document->stream;
        $most = Document::MAX_FILE_BYTES + 1;
        if ($stream instanceof StreamInterface) {
            try {
                $data = '';
                while (!$stream->eof() && strlen($data) < $most) {
                    $chunk = $stream->read(min(1 << 20, $most - strlen($data)));
                    if ($chunk === '') {
                        break;
                    }
                    $data .= $chunk;
                }

                return $data;
            } catch (\RuntimeException $e) {
                throw new \InvalidArgumentException('Document: cannot read the stream: ' . $e->getMessage(), 0, $e);
            }
        }
        if (is_resource($stream)) {
            $data = @stream_get_contents($stream, $most);
            if ($data === false) {
                throw new \InvalidArgumentException('Document: cannot read the stream.');
            }

            return $data;
        }

        throw new \InvalidArgumentException('Document: no text, file or upload id.');
    }

    /**
     * The type given, else the file name's extension's, else the one the
     * first bytes show.
     *
     * @throws BadRequestError when it is not a knowledge type
     * @throws \InvalidArgumentException when it cannot be told
     */
    private static function contentType(Document $document, string $bytes, int $index): string
    {
        if ($document->contentType !== null) {
            $type = $document->contentType;
        } else {
            $type = null;
            if ($document->filename !== null) {
                $dot = strrpos($document->filename, '.');
                $type = $dot === false ? null : (self::EXTENSIONS[strtolower(substr($document->filename, $dot + 1))] ?? null);
            }
            $type ??= self::sniff($bytes);
        }
        if ($type === null) {
            throw new \InvalidArgumentException(sprintf('Document %d: its type cannot be told from its name or its first bytes: give a content type or a file name.', $index));
        }
        if (!in_array($type, Document::CONTENT_TYPES, true)) {
            throw self::bad(sprintf('files[%d].content_type: must be one of %s, got %s.', $index, implode(', ', Document::CONTENT_TYPES), $type));
        }

        return $type;
    }

    /**
     * The type the first bytes show: PDF, PNG, JPEG, TIFF or WebP; null for
     * anything else (a ZIP container and text cannot be told).
     */
    public static function sniff(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, '%PDF-') => 'application/pdf',
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "II*\0"), str_starts_with($bytes, "MM\0*") => 'image/tiff',
            strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    private static function tooLarge(int $index, int $size): BadRequestError
    {
        return self::bad(sprintf('files[%d].byte_size: %d is over the limit of %d bytes.', $index, $size, Document::MAX_FILE_BYTES));
    }

    private static function bad(string $message): BadRequestError
    {
        return new BadRequestError($message, errorType: 'api_usage_error', reason: $message);
    }
}
