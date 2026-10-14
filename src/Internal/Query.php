<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

/**
 * A query string from name and value pairs, in order, a name repeated as
 * often as it is given, each part escaped as RFC 3986 asks.
 *
 * @internal
 */
final class Query
{
    /**
     * @param list<array{0: string, 1: string}> $pairs
     *
     * @return string "" for no pairs, else "?" and the pairs
     */
    public static function encode(array $pairs): string
    {
        $parts = [];
        foreach ($pairs as [$name, $value]) {
            $parts[] = rawurlencode($name) . '=' . rawurlencode($value);
        }

        return $parts === [] ? '' : '?' . implode('&', $parts);
    }

    /**
     * `label=name:value` pairs, by name, so a map sends the same query
     * every time.
     *
     * @param array<array-key, mixed>|null $labels
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function labels(?array $labels): array
    {
        $labels ??= [];
        ksort($labels, SORT_STRING);
        $pairs = [];
        foreach ($labels as $name => $value) {
            $pairs[] = ['label', $name . ':' . (is_scalar($value) ? (string) $value : '')];
        }

        return $pairs;
    }
}
