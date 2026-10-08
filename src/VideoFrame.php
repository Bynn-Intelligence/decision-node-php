<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

use Bynn\DecisionNode\Internal\ImageHeader;

/**
 * One frame of a {@see Video}: a still image and its time in seconds.
 *
 *     VideoFrame::fromData(0.0, $jpegBytes)               // the type is read from the first bytes
 *     VideoFrame::fromBase64(0.5, $base64, 'image/jpeg')
 *     VideoFrame::fromPath(1.0, '/frames/dock-0001.jpg')
 *
 * A frame is a JPEG, PNG or WebP image the caller already holds, such as a
 * camera's snapshot. It is always sent inline as base64, never uploaded and
 * never resized or re-encoded. `t` is the frame's time in seconds, at least 0
 * and larger than the time of the frame before it in the same video.
 */
final class VideoFrame implements \JsonSerializable
{
    /** Seconds, as given. */
    public readonly int|float $t;

    /** The raw bytes, or null for a frame given as base64 text. */
    public readonly ?string $data;

    /** The base64 text as given, or null for a frame given as bytes. */
    public readonly ?string $base64;

    /**
     * The media type: the one given, else the one read from the bytes. Null
     * for base64 text or bytes of an unknown format given without one (the
     * request then fails before it is sent).
     */
    public readonly ?string $mediaType;

    /**
     * Exactly one of `$data` and `$base64` must be given. `$mediaType` is
     * needed with `$base64`, and read from the first bytes of `$data` when
     * left out; a frame without one fails before the request is sent.
     *
     * @throws \InvalidArgumentException when not exactly one of `$data` and `$base64` is given
     */
    public function __construct(int|float $t, ?string $data = null, ?string $mediaType = null, ?string $base64 = null)
    {
        if (($data === null) === ($base64 === null)) {
            throw new \InvalidArgumentException('VideoFrame: give exactly one of data and base64.');
        }
        $mediaType = $mediaType === '' ? null : $mediaType;
        $this->t = $t;
        $this->data = $data;
        $this->base64 = $base64;
        $this->mediaType = $mediaType ?? ($data !== null ? ImageHeader::mediaType($data) : null);
    }

    /**
     * Raw bytes. Without `$mediaType` the type is read from the first bytes.
     */
    public static function fromData(int|float $t, string $data, ?string $mediaType = null): self
    {
        return new self($t, data: $data, mediaType: $mediaType);
    }

    /**
     * Base64 text, sent as given, with its media type.
     */
    public static function fromBase64(int|float $t, string $base64, ?string $mediaType): self
    {
        return new self($t, mediaType: $mediaType, base64: $base64);
    }

    /**
     * A file, read here. Without `$mediaType` the type is read from the first bytes.
     *
     * @throws \InvalidArgumentException when the file cannot be read
     */
    public static function fromPath(int|float $t, string $path, ?string $mediaType = null): self
    {
        $data = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;
        if ($data === false) {
            throw new \InvalidArgumentException(sprintf('Video frame at %s s: cannot read %s.', Internal\Json::encode($t), $path));
        }

        return new self($t, data: $data, mediaType: $mediaType);
    }

    /**
     * The frame's wire form: `{t, media_type, data}` with the data as base64.
     *
     * @return array{t: int|float, media_type: string|null, data: string}
     */
    public function toArray(): array
    {
        return [
            't' => $this->t,
            'media_type' => $this->mediaType,
            'data' => $this->base64 ?? base64_encode((string) $this->data),
        ];
    }

    /**
     * @return array{t: int|float, media_type: string|null, data: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
