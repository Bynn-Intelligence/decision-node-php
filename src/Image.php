<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

use Bynn\DecisionNode\Internal\ImageHeader;
use Psr\Http\Message\StreamInterface;

/**
 * An image sent with a request. Its `id` is unique within the request; the
 * model sees the image as "Picture <n>" (its place in the request's images,
 * from 1) with this id beside it, so text in the state or the instructions can
 * refer to it by either.
 *
 *     Image::fromUrl('receipt', 'https://example.com/receipt.jpg')   // recommended: nothing is uploaded
 *     Image::fromPath('receipt', '/path/to/receipt.jpg')
 *     Image::fromData('receipt', $bytes)                              // raw bytes
 *     Image::fromBase64('receipt', $base64)
 *     Image::fromStream('receipt', $resourceOrPsr7Stream)
 *     Image::fromUploadId('receipt', 'upl_...')                       // an upload made earlier
 *
 * or `new Image('receipt', url: ...)` and the other named arguments.
 *
 * A URL is sent as it is and fetched by the API, so nothing is uploaded: it is
 * the fastest form. A file, bytes, base64 or a stream are read when the Image
 * is made and checked by the client before any request: JPEG, PNG or WebP by
 * their first bytes, at most 10 MB, at most 40 megapixels by their header.
 * The client then sends them inline when a request's local images are small,
 * and uploads them once otherwise. No image is resized or re-encoded.
 */
final class Image implements \JsonSerializable
{
    public readonly string $id;

    /** The raw bytes, or null for an image given by URL or upload id. */
    public readonly ?string $data;

    /**
     * The media type: the one given, else the one read from the bytes. Null
     * for a URL or an upload id given without one, and for bytes of an
     * unknown format (the request then fails before it is sent).
     */
    public readonly ?string $mediaType;

    public readonly ?string $url;

    public readonly ?string $uploadId;

    /** The media type the caller gave, when it gave one. */
    public readonly ?string $declaredMediaType;

    /**
     * Exactly one of `$path`, `$data`, `$base64`, `$stream`, `$url` or
     * `$uploadId` must be given.
     *
     * A file or stream is read here, so an unreadable one fails when the
     * Image is made. The format, size and pixel checks run when the image is
     * sent, and raise the API's own BadRequestError before any request.
     *
     * @param resource|StreamInterface|null $stream
     *
     * @throws \InvalidArgumentException when not exactly one source is given, the file or stream
     *                                   cannot be read, or `$base64` is not base64
     */
    public function __construct(
        string $id,
        ?string $path = null,
        ?string $data = null,
        ?string $mediaType = null,
        ?string $url = null,
        ?string $uploadId = null,
        ?string $base64 = null,
        mixed $stream = null,
    ) {
        $given = count(array_filter([$path, $data, $url, $uploadId, $base64, $stream], static fn (mixed $v): bool => $v !== null));
        if ($given !== 1) {
            throw new \InvalidArgumentException(sprintf('Image "%s": give exactly one of path, data, base64, stream, url or uploadId.', $id));
        }

        if ($path !== null) {
            $data = self::read($id, $path);
        } elseif ($base64 !== null) {
            $decoded = base64_decode($base64, true);
            if ($decoded === false) {
                throw new \InvalidArgumentException(sprintf('Image "%s": the base64 text cannot be decoded.', $id));
            }
            $data = $decoded;
        } elseif ($stream !== null) {
            $data = self::readStream($id, $stream);
        }

        $this->id = $id;
        $this->data = $data;
        $this->url = $url;
        $this->uploadId = $uploadId;
        $this->declaredMediaType = $mediaType === '' ? null : $mediaType;
        $this->mediaType = $this->declaredMediaType ?? ($data !== null ? ImageHeader::mediaType($data) : null);
    }

    public static function fromPath(string $id, string $path): self
    {
        return new self($id, path: $path);
    }

    /**
     * Raw bytes. Without `$mediaType` the type is read from the first bytes.
     */
    public static function fromData(string $id, string $data, ?string $mediaType = null): self
    {
        return new self($id, data: $data, mediaType: $mediaType);
    }

    public static function fromBase64(string $id, string $base64, ?string $mediaType = null): self
    {
        return new self($id, base64: $base64, mediaType: $mediaType);
    }

    /**
     * @param resource|StreamInterface $stream read to its end here
     */
    public static function fromStream(string $id, mixed $stream, ?string $mediaType = null): self
    {
        return new self($id, stream: $stream, mediaType: $mediaType);
    }

    public static function fromUrl(string $id, string $url): self
    {
        return new self($id, url: $url);
    }

    /**
     * An image uploaded earlier with {@see DecisionNode::upload()}, by its upload id.
     */
    public static function fromUploadId(string $id, string $uploadId, ?string $mediaType = null): self
    {
        return new self($id, uploadId: $uploadId, mediaType: $mediaType);
    }

    /**
     * The media type of JPEG, PNG and WebP data, read from its first bytes, or
     * null for anything else.
     */
    public static function sniff(string $data): ?string
    {
        return ImageHeader::mediaType($data);
    }

    /**
     * Whether the image is local bytes (a path, data, base64 or a stream),
     * which the client sends inline or uploads.
     */
    public function isLocal(): bool
    {
        return $this->data !== null;
    }

    /**
     * The image in its inline wire form: `{id, url}`, `{id, upload_id}` or
     * `{id, media_type, data}`. The client itself may upload local bytes and
     * send `{id, upload_id}` instead.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        if ($this->url !== null) {
            return ['id' => $this->id, 'url' => $this->url] + ($this->declaredMediaType !== null ? ['media_type' => $this->declaredMediaType] : []);
        }
        if ($this->uploadId !== null) {
            return ['id' => $this->id, 'upload_id' => $this->uploadId] + ($this->declaredMediaType !== null ? ['media_type' => $this->declaredMediaType] : []);
        }

        return ['id' => $this->id, 'media_type' => (string) $this->mediaType, 'data' => base64_encode((string) $this->data)];
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function read(string $id, string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(sprintf('Image "%s": cannot read %s.', $id, $path));
        }
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new \InvalidArgumentException(sprintf('Image "%s": cannot read %s.', $id, $path));
        }

        return $data;
    }

    private static function readStream(string $id, mixed $stream): string
    {
        if ($stream instanceof StreamInterface) {
            try {
                if ($stream->isSeekable()) {
                    $stream->rewind();
                }

                return $stream->getContents();
            } catch (\RuntimeException $e) {
                throw new \InvalidArgumentException(sprintf('Image "%s": cannot read the stream: %s', $id, $e->getMessage()), 0, $e);
            }
        }
        if (is_resource($stream)) {
            $data = @stream_get_contents($stream);
            if ($data === false) {
                throw new \InvalidArgumentException(sprintf('Image "%s": cannot read the stream.', $id));
            }

            return $data;
        }

        throw new \InvalidArgumentException(sprintf('Image "%s": stream must be a resource or a PSR-7 stream, got %s.', $id, get_debug_type($stream)));
    }
}
