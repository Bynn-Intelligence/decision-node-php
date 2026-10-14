<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * One page of `usage->list()`: the `period` as applied, `asOf`, the keys'
 * `items`, and `nextCursor`, the next page's cursor, null on the last page.
 * A page can hold fewer keys than its limit: follow `nextCursor` until it is
 * null, or call `usage->all()`.
 */
final class UsagePage
{
    /**
     * @param array<array-key, mixed>|null $period
     * @param list<UsageItem>              $items
     * @param array<array-key, mixed>      $raw    the page exactly as the API sent it
     */
    public function __construct(
        public readonly ?array $period,
        public readonly ?string $asOf,
        public readonly array $items,
        public readonly ?string $nextCursor,
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
        $items = [];
        foreach (is_array($raw['items'] ?? null) ? $raw['items'] : [] as $item) {
            if (is_array($item)) {
                $items[] = UsageItem::fromArray($item);
            }
        }

        return new self(
            UsageItem::object($raw['period'] ?? null),
            is_string($raw['as_of'] ?? null) ? $raw['as_of'] : null,
            $items,
            is_string($raw['next_cursor'] ?? null) && $raw['next_cursor'] !== '' ? $raw['next_cursor'] : null,
            $raw,
        );
    }
}
