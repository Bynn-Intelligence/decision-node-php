<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * One page of `keys->list()`, newest first: the `items` and `nextCursor`,
 * the next page's cursor, null on the last page.
 */
final class KeyPage
{
    /**
     * @param list<Key>               $items
     * @param array<array-key, mixed> $raw   the page exactly as the API sent it
     */
    public function __construct(
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
                $items[] = Key::fromArray($item);
            }
        }

        return new self($items, is_string($raw['next_cursor'] ?? null) && $raw['next_cursor'] !== '' ? $raw['next_cursor'] : null, $raw);
    }
}
