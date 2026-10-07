<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

/**
 * @internal
 */
final class Json
{
    private const ENCODE = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value): string
    {
        try {
            return json_encode($value, self::ENCODE);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('The request cannot be serialised as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Decodes a body that is known to be JSON with objects kept as \stdClass,
     * so a JSON object and a JSON list can be told apart. Null when it is not JSON.
     */
    public static function decodeObjects(string $text): mixed
    {
        try {
            return json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Decodes a response body into arrays. Throws \JsonException on invalid JSON.
     */
    public static function decode(string $text): mixed
    {
        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }
}
