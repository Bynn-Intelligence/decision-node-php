<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * One key's usage on a page of `usage->list()`: the `key` as it is now
 * (`id`, `name`, `scope`, `device_id`, `labels`, `status`, `created_at`,
 * `revoked_at`, `expires_at`), its `totals` for the period (`requests`,
 * `free_tier_requests`, `input_tokens`, `image_tokens`, `video_tokens`,
 * `booster_calls` and `cost`), and, when asked for, its `buckets` (with a
 * granularity), `byModel` or `byChannel` (with a `groupBy`); each as sent,
 * null when not sent.
 */
final class UsageItem
{
    /**
     * @param array<array-key, mixed>|null       $key
     * @param array<array-key, mixed>|null       $totals
     * @param list<array<array-key, mixed>>|null $buckets
     * @param list<array<array-key, mixed>>|null $byModel
     * @param list<array<array-key, mixed>>|null $byChannel
     * @param array<array-key, mixed>            $raw       the entry exactly as the API sent it
     */
    public function __construct(
        public readonly ?array $key,
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
            self::object($raw['key'] ?? null),
            self::object($raw['totals'] ?? null),
            self::objects($raw['buckets'] ?? null),
            self::objects($raw['by_model'] ?? null),
            self::objects($raw['by_channel'] ?? null),
            $raw,
        );
    }

    /**
     * @internal
     *
     * @return array<array-key, mixed>|null
     */
    public static function object(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    /**
     * @internal
     *
     * @return list<array<array-key, mixed>>|null
     */
    public static function objects(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
