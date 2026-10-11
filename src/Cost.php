<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * What an answer cost, in USD, exactly as the API sent it: `usd` the whole,
 * `modelUsd` and `boostersUsd` its parts, `freeTier` true when it came out of
 * the free tier. Read `usd` rather than adding up the parts: the API rounds
 * each amount on its own.
 */
final class Cost
{
    public function __construct(
        public readonly int|float $usd,
        public readonly int|float $modelUsd,
        public readonly int|float $boostersUsd,
        public readonly bool $freeTier,
    ) {
    }

    /**
     * @internal
     *
     * @return self|null null when the usage has no cost, or one of another shape
     */
    public static function fromUsage(mixed $usage): ?self
    {
        $cost = is_array($usage) ? ($usage['cost'] ?? null) : null;
        if (!is_array($cost)) {
            return null;
        }
        $number = static fn (mixed $v): bool => is_int($v) || is_float($v);
        if (!$number($cost['usd'] ?? null) || !$number($cost['model_usd'] ?? null) || !$number($cost['boosters_usd'] ?? null) || !is_bool($cost['free_tier'] ?? null)) {
            return null;
        }

        return new self($cost['usd'], $cost['model_usd'], $cost['boosters_usd'], $cost['free_tier']);
    }
}
