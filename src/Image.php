<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * An image sent with a request, referred to by its `id`.
 *
 *     Image::fromPath('receipt', '/path/to/receipt.jpg')
 *     Image::fromData('receipt', $bytes, 'image/png')
 *     Image::fromUrl('receipt', 'https://example.com/receipt.jpg')
 *
 * or `new Image('receipt', path: ...)`, `new Image('receipt', data: ..., mediaType: ...)`,
 * `new Image('receipt', url: ...)`.
 *
 * A file is read and base64-encoded by the SDK, and its media type is taken
 * from its first bytes (JPEG, PNG or WebP). The API accepts up to 4 images a
 * request, 10 MB each, and only `https` URLs; the server enforces the limits.
 */
final class Image implements \JsonSerializable
{
    public readonly string $id;

    /** The raw bytes, or null for an image given by URL. */
    public readonly ?string $data;

    public readonly ?string $mediaType;

    public readonly ?string $url;

    /**
     * Exactly one of `$path`, `$data` or `$url` must be given.
     *
     * A file is read here, so an unreadable file fails when the Image is made.
     * Bytes given without a media type have it taken from their first bytes.
     *
     * @throws \InvalidArgumentException when not exactly one source is given, the file cannot be read,
     *                                   or the file or bytes without a media type are not a JPEG, PNG or WebP image
     */
    public function __construct(
        string $id,
        ?string $path = null,
        ?string $data = null,
        ?string $mediaType = null,
        ?string $url = null,
    ) {
        $given = ($path !== null ? 1 : 0) + ($data !== null ? 1 : 0) + ($url !== null ? 1 : 0);
        if ($given !== 1) {
            throw new \InvalidArgumentException(sprintf('Image "%s": give exactly one of path, data or url.', $id));
        }

        if ($path !== null) {
            $data = self::read($id, $path);
            $mediaType = self::sniff($data);
            if ($mediaType === null) {
                throw new \InvalidArgumentException(sprintf('Image "%s": %s is not a JPEG, PNG or WebP file.', $id, $path));
            }
        } elseif ($data !== null && $mediaType === null) {
            $mediaType = self::sniff($data);
            if ($mediaType === null) {
                throw new \InvalidArgumentException(sprintf('Image "%s": the bytes are not a JPEG, PNG or WebP image; pass mediaType for another format.', $id));
            }
        }

        $this->id = $id;
        $this->data = $data;
        $this->mediaType = $url !== null ? null : $mediaType;
        $this->url = $url;
    }

    public static function fromPath(string $id, string $path): self
    {
        return new self($id, path: $path);
    }

    public static function fromData(string $id, string $data, ?string $mediaType = null): self
    {
        return new self($id, data: $data, mediaType: $mediaType);
    }

    public static function fromUrl(string $id, string $url): self
    {
        return new self($id, url: $url);
    }

    /**
     * The media type of JPEG, PNG and WebP data, read from its first bytes, or
     * null for anything else.
     */
    public static function sniff(string $data): ?string
    {
        if (str_starts_with($data, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($data, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (strlen($data) >= 12 && substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }

    /**
     * The image in its wire form.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        if ($this->url !== null) {
            return ['id' => $this->id, 'url' => $this->url];
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
}
