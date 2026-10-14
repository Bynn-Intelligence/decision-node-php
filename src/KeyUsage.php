<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * One key's usage over a period, from `usage->key()`: the `key` as it is
 * now, the `period` as applied (`start` and `end`, rounded to the hour),
 * `asOf`, up to when the figures are complete, the period's `totals`, and,
 * when asked for, its `buckets`, `byModel` or `byChannel`; each as sent,
 * null when not sent.
 */
final class KeyUsage
{
    /**
     * @param array<array-key, mixed>|null       $key
     * @param array<array-key, mixed>|null       $period
     * @param array<array-key, mixed>|null       $totals
     * @param list<array<array-key, mixed>>|null $buckets
     * @param list<array<array-key, mixed>>|null $byModel
     * @param list<array<array-key, mixed>>|null $byChannel
     * @param array<array-key, mixed>            $raw       the answer exactly as the API sent it
     */
    public function __construct(
        public readonly ?array $key,
        public readonly ?array $period,
        public readonly ?string $asOf,
        public readonly ?array $totals,
        public readonly ?array $buckets,
        public readonly ?array $byModel,
        public readonly ?array $byChannel,
        public readonly array $raw,
    ) {
    }

    /**
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            UsageItem::object($raw['key'] ?? null),
            UsageItem::object($raw['period'] ?? null),
            is_string($raw['as_of'] ?? null) ? $raw['as_of'] : null,
            UsageItem::object($raw['totals'] ?? null),
            UsageItem::objects($raw['buckets'] ?? null),
            UsageItem::objects($raw['by_model'] ?? null),
            UsageItem::objects($raw['by_channel'] ?? null),
            $raw,
        );
    }
}
