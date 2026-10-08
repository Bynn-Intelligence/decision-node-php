<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

/**
 * Reads the format and the pixel size of a JPEG, PNG or WebP image from its
 * first bytes, without decoding it.
 *
 * @internal
 */
final class ImageHeader
{
    /** JPEG start-of-frame markers, which carry the frame's height and width. */
    private const JPEG_SOF = [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF];

    /**
     * The media type of JPEG, PNG and WebP data from its magic bytes, or null
     * for anything else.
     */
    public static function mediaType(string $data): ?string
    {
        if (str_starts_with($data, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($data, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (strlen($data) >= 12 && str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }

    /**
     * Width and height in pixels, read from the header of `$data` of the
     * given media type, or null when the header cannot be read.
     *
     * @return array{int, int}|null
     */
    public static function dimensions(string $data, string $mediaType): ?array
    {
        return match ($mediaType) {
            'image/png' => self::png($data),
            'image/jpeg' => self::jpeg($data),
            'image/webp' => self::webp($data),
            default => null,
        };
    }

    /**
     * @return array{int, int}|null
     */
    private static function png(string $data): ?array
    {
        if (strlen($data) < 24 || substr($data, 12, 4) !== 'IHDR') {
            return null;
        }
        $size = unpack('Nwidth/Nheight', substr($data, 16, 8));

        return is_array($size) ? [(int) $size['width'], (int) $size['height']] : null;
    }

    /**
     * Walks the JPEG markers to the first start-of-frame segment.
     *
     * @return array{int, int}|null
     */
    private static function jpeg(string $data): ?array
    {
        $length = strlen($data);
        $i = 2;
        while ($i + 9 < $length) {
            if (ord($data[$i]) !== 0xFF) {
                return null;
            }
            $marker = ord($data[$i + 1]);
            if ($marker === 0xFF) {
                // Fill byte before a marker.
                ++$i;
                continue;
            }
            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $i += 2;
                continue;
            }
            if (in_array($marker, self::JPEG_SOF, true)) {
                $size = unpack('nheight/nwidth', substr($data, $i + 5, 4));

                return is_array($size) ? [(int) $size['width'], (int) $size['height']] : null;
            }
            $segment = unpack('n', substr($data, $i + 2, 2));
            if (!is_array($segment) || (int) $segment[1] < 2) {
                return null;
            }
            $i += 2 + (int) $segment[1];
        }

        return null;
    }

    /**
     * @return array{int, int}|null
     */
    private static function webp(string $data): ?array
    {
        if (strlen($data) < 30) {
            return null;
        }
        $chunk = substr($data, 12, 4);
        if ($chunk === 'VP8 ') {
            // Lossy: a key frame's start code, then 14-bit width and height.
            if (substr($data, 23, 3) !== "\x9D\x01\x2A") {
                return null;
            }
            $size = unpack('vwidth/vheight', substr($data, 26, 4));

            return is_array($size) ? [(int) $size['width'] & 0x3FFF, (int) $size['height'] & 0x3FFF] : null;
        }
        if ($chunk === 'VP8L') {
            // Lossless: a signature byte, then width-1 and height-1 in 14 bits each.
            if (ord($data[20]) !== 0x2F) {
                return null;
            }
            $bits = unpack('V', substr($data, 21, 4));
            if (!is_array($bits)) {
                return null;
            }
            $value = (int) $bits[1];

            return [($value & 0x3FFF) + 1, (($value >> 14) & 0x3FFF) + 1];
        }
        if ($chunk === 'VP8X') {
            // Extended: the canvas width-1 and height-1 in 24 bits each.
            $width = ord($data[24]) | (ord($data[25]) << 8) | (ord($data[26]) << 16);
            $height = ord($data[27]) | (ord($data[28]) << 8) | (ord($data[29]) << 16);

            return [$width + 1, $height + 1];
        }

        return null;
    }
}
