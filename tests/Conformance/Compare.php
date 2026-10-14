<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Conformance;

/**
 * The comparison rules of the conformance suite, for PHP values.
 *
 * Expected values come from the case files with JSON objects as \stdClass.
 * Actual values are the SDK's: PHP arrays for maps and lists. Numbers compare
 * exactly as doubles, booleans never equal numbers, maps compare by key (an
 * integer key 3 equals the JSON key "3", as PHP stores it), and a PHP empty
 * array stands for both {} and [].
 */
final class Compare
{
    private const EXACT = ['probabilities', 'legend', 'raw', 'entries', 'safety', 'body', 'limits'];

    /** Maps compared whole and in their order: a decision's evidence, an error's boosters. */
    private const ORDERED = ['evidence', 'boosters'];

    public static function same(mixed $expected, mixed $actual): bool
    {
        // An expected {"$pattern": regex} matches a string the regex finds.
        if ($expected instanceof \stdClass && array_keys(get_object_vars($expected)) === ['$pattern'] && is_string($expected->{'$pattern'})) {
            return is_string($actual) && preg_match('~' . str_replace('~', '\~', $expected->{'$pattern'}) . '~u', $actual) === 1;
        }
        if (is_bool($expected) || is_bool($actual)) {
            return is_bool($expected) && is_bool($actual) && $expected === $actual;
        }
        if ((is_int($expected) || is_float($expected)) && (is_int($actual) || is_float($actual))) {
            return (float) $expected === (float) $actual;
        }
        if ($expected === null || $actual === null) {
            return $expected === null && $actual === null;
        }
        if (is_string($expected) || is_string($actual)) {
            return $expected === $actual;
        }
        if ($expected instanceof \stdClass) {
            $want = get_object_vars($expected);
            $have = self::map($actual);
            if ($have === null) {
                return false;
            }
            $wantKeys = array_map('strval', array_keys($want));
            $haveKeys = array_map('strval', array_keys($have));
            sort($wantKeys, SORT_STRING);
            sort($haveKeys, SORT_STRING);
            if ($wantKeys !== $haveKeys) {
                return false;
            }
            foreach ($want as $key => $value) {
                if (!self::same($value, $have[$key])) {
                    return false;
                }
            }

            return true;
        }
        if (is_array($expected)) {
            if ($actual instanceof \stdClass) {
                return $expected === [] && get_object_vars($actual) === [];
            }
            if (!is_array($actual) || !array_is_list($actual) || count($actual) !== count($expected)) {
                return false;
            }
            foreach (array_values($expected) as $i => $value) {
                if (!self::same($value, $actual[$i])) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Every key of `$expected` must be in `$actual` with the same value; the
     * keys in EXACT compare whole, the others recursively by their own keys.
     */
    public static function match(mixed $expected, mixed $actual, string $path): void
    {
        if ($expected instanceof \stdClass && array_keys(get_object_vars($expected)) !== ['$pattern']) {
            $have = self::map($actual);
            if ($have !== null) {
                foreach (get_object_vars($expected) as $key => $value) {
                    if ($value instanceof \stdClass && get_object_vars($value) === ['$absent' => true]) {
                        // Suite 1.17: the key must not be there at all.
                        if (array_key_exists($key, $have)) {
                            throw new Fail(sprintf('%s.%s: expected no such key, got %s', $path, $key, self::show($have[$key])));
                        }
                        continue;
                    }
                    if (!array_key_exists($key, $have)) {
                        throw new Fail(sprintf('%s.%s: missing (expected %s)', $path, $key, self::show($value)));
                    }
                    if (in_array((string) $key, self::ORDERED, true) && $value instanceof \stdClass) {
                        $order = self::map($have[$key]);
                        if (!self::same($value, $have[$key]) || $order === null
                            || array_map('strval', array_keys(get_object_vars($value))) !== array_map('strval', array_keys($order))) {
                            throw new Fail(sprintf('%s.%s: expected %s in this order, got %s', $path, $key, self::show($value), self::show($have[$key])));
                        }
                    } elseif (in_array((string) $key, self::EXACT, true)) {
                        if (!self::same($value, $have[$key])) {
                            throw new Fail(sprintf('%s.%s: expected %s, got %s', $path, $key, self::show($value), self::show($have[$key])));
                        }
                    } else {
                        self::match($value, $have[$key], $path . '.' . $key);
                    }
                }

                return;
            }
        }
        if (!self::same($expected, $actual)) {
            throw new Fail(sprintf('%s: expected %s, got %s', $path, self::show($expected), self::show($actual)));
        }
    }

    /**
     * @return array{bool, mixed}
     */
    public static function pointer(mixed $doc, string $pointer): array
    {
        if ($pointer === '') {
            return [true, $doc];
        }
        $current = $doc;
        foreach (explode('/', substr($pointer, 1)) as $raw) {
            $part = str_replace(['~1', '~0'], ['/', '~'], $raw);
            if ($current instanceof \stdClass && property_exists($current, $part)) {
                $current = $current->{$part};
            } elseif (is_array($current) && ctype_digit($part) && array_key_exists((int) $part, $current)) {
                $current = $current[(int) $part];
            } else {
                return [false, null];
            }
        }

        return [true, $current];
    }

    public static function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            'array' => is_array($value),
            'object' => $value instanceof \stdClass,
            default => false,
        };
    }

    public static function show(mixed $value): string
    {
        $text = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return strlen((string) $text) < 200 ? (string) $text : substr((string) $text, 0, 197) . '...';
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function map(mixed $value): ?array
    {
        if ($value instanceof \stdClass) {
            return get_object_vars($value);
        }

        return is_array($value) ? $value : null;
    }
}
