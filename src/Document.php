<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

use Psr\Http\Message\StreamInterface;

/**
 * The content of one document of a knowledge base (a collection of type
 * `text`), for `collections->addDocument()`, `replaceDocument()` and
 * `$dn->uploadDocuments()`.
 *
 *     Document::fromText("# Returns\n\nRefunds within 30 days.");    // Markdown, sent inline
 *     Document::fromPath('policies/claims-2026.pdf');                 // uploaded, then converted to text
 *     Document::fromData($bytes, 'application/pdf', 'claims.pdf');
 *     Document::fromStream(fopen('handbook.docx', 'rb'));             // needs a type or a file name
 *     Document::fromUploadId('upl_...');                              // uploaded before
 *
 * A file's type is `$contentType` when given, else read from the file
 * name's extension, else from its first bytes (PDF, PNG, JPEG, TIFF, WebP).
 * DOCX, PPTX, XLSX and text cannot be told from their bytes: give them a
 * type or a file name. A text or Markdown file under the client's inline
 * threshold that is UTF-8 is sent inline as text; every other file is
 * uploaded first. A path is read, and a stream read to its end, when the
 * call is made.
 */
final class Document
{
    /** Markdown, the API's default format. */
    public const MARKDOWN = 'markdown';

    /** Plain text, cut by its paragraphs. */
    public const TEXT = 'text';

    /** The largest file a document may be: 50 MB. */
    public const MAX_FILE_BYTES = 52_428_800;

    /**
     * The types a knowledge base takes, in the API's order.
     */
    public const CONTENT_TYPES = [
        'text/plain',
        'text/markdown',
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/html',
        'image/png',
        'image/jpeg',
        'image/tiff',
        'image/webp',
    ];

    /**
     * @param resource|StreamInterface|null $stream
     */
    private function __construct(
        public readonly ?string $text = null,
        public readonly ?string $format = null,
        public readonly ?string $path = null,
        public readonly ?string $data = null,
        public readonly mixed $stream = null,
        public readonly ?string $contentType = null,
        public readonly ?string $filename = null,
        public readonly ?string $uploadId = null,
    ) {
    }

    /**
     * Text held in memory, sent inline whatever its size (the API takes 512
     * KiB once normalised). `$format` is `markdown` (the API's default) or
     * `text`, sent only when given.
     */
    public static function fromText(string $text, ?string $format = null): self
    {
        return new self(text: $text, format: $format);
    }

    /**
     * A local file, read when the call is made. `$filename` is the path's
     * base name unless given.
     */
    public static function fromPath(string $path, ?string $contentType = null, ?string $filename = null): self
    {
        return new self(path: $path, contentType: $contentType, filename: $filename ?? basename($path));
    }

    /**
     * The file's bytes.
     */
    public static function fromData(string $data, ?string $contentType = null, ?string $filename = null): self
    {
        return new self(data: $data, contentType: $contentType, filename: $filename);
    }

    /**
     * A readable stream, read to its end once, into memory, when the call is
     * made; reading stops past 50 MB and the call fails as a file over the
     * limit does.
     *
     * @param resource|StreamInterface $stream
     */
    public static function fromStream(mixed $stream, ?string $contentType = null, ?string $filename = null): self
    {
        if (!$stream instanceof StreamInterface && !is_resource($stream)) {
            throw new \InvalidArgumentException(sprintf('stream must be a resource or a PSR-7 stream, got %s.', get_debug_type($stream)));
        }

        return new self(stream: $stream, contentType: $contentType, filename: $filename);
    }

    /**
     * A knowledge upload made before with {@see DecisionNode::uploadDocuments()},
     * sent as given.
     */
    public static function fromUploadId(string $uploadId): self
    {
        return new self(uploadId: $uploadId);
    }

    /** Whether the document is text held in memory. */
    public function isText(): bool
    {
        return $this->text !== null;
    }

    /** Whether the document is a file: a path, bytes or a stream. */
    public function isFile(): bool
    {
        return $this->path !== null || $this->data !== null || $this->stream !== null;
    }
}
