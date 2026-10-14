<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * A key object of `/v1/keys`, every field as sent, null when not sent:
 * `id` (`key_` and 32 hex digits), `name`, `prefix` (the start of the
 * secret), `scope` (`decide` for every key `/v1/keys` makes), `last4` (the
 * secret's last four characters), `deviceId`, `labels` (name => value),
 * `limits` (`requests_per_second` and `tokens_per_second`, null when the
 * workspace's limits apply), `dailySpendCap` and `spentToday` (each
 * `{"usd"}`, null without a cap), `expiresAt`, `maySearchCollections`,
 * `status` (`active`, `expired` or `revoked`), `createdByKey` (`{"id"}`,
 * null for a key made in the console), `createdAt`, `lastUsedAt` and
 * `revokedAt`. A key's secret is never in it: `keys->create()` returns it
 * once.
 */
final class Key
{
    /**
     * @param array<string, string>        $labels
     * @param array<array-key, mixed>|null $limits
     * @param array<array-key, mixed>|null $dailySpendCap
     * @param array<array-key, mixed>|null $spentToday
     * @param array<array-key, mixed>|null $createdByKey
     * @param array<array-key, mixed>      $raw          the key object exactly as the API sent it
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $name,
        public readonly ?string $prefix,
        public readonly ?string $scope,
        public readonly ?string $last4,
        public readonly ?string $deviceId,
        public readonly array $labels,
        public readonly ?array $limits,
        public readonly ?array $dailySpendCap,
        public readonly ?array $spentToday,
        public readonly ?string $expiresAt,
        public readonly ?bool $maySearchCollections,
        public readonly ?string $status,
        public readonly ?array $createdByKey,
        public readonly ?string $createdAt,
        public readonly ?string $lastUsedAt,
        public readonly ?string $revokedAt,
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
        $string = static fn (string $key): ?string => is_string($raw[$key] ?? null) ? $raw[$key] : null;
        $object = static fn (string $key): ?array => is_array($raw[$key] ?? null) ? $raw[$key] : null;
        $labels = [];
        foreach (is_array($raw['labels'] ?? null) ? $raw['labels'] : [] as $name => $value) {
            if (is_string($value)) {
                $labels[(string) $name] = $value;
            }
        }

        return new self(
            id: $string('id'),
            name: $string('name'),
            prefix: $string('prefix'),
            scope: $string('scope'),
            last4: $string('last4'),
            deviceId: $string('device_id'),
            labels: $labels,
            limits: $object('limits'),
            dailySpendCap: $object('daily_spend_cap'),
            spentToday: $object('spent_today'),
            expiresAt: $string('expires_at'),
            maySearchCollections: is_bool($raw['may_search_collections'] ?? null) ? $raw['may_search_collections'] : null,
            status: $string('status'),
            createdByKey: $object('created_by_key'),
            createdAt: $string('created_at'),
            lastUsedAt: $string('last_used_at'),
            revokedAt: $string('revoked_at'),
            raw: $raw,
        );
    }
}
