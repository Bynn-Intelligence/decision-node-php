<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * One page of `collections->listItems()`, newest first. `nextOffset` is the
 * offset of the next page, or null on the last page. `totalCapped` is true
 * when `total` stopped counting at the server's cap; `reference` and
 * `referencePrefix` are the page's lookup as sent, null when none.
 */
final class CollectionItemPage
{
    /**
     * @param list<CollectionItem>    $items
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public readonly array $items,
        public readonly ?int $offset,
        public readonly ?int $limit,
        public readonly ?int $total,
        public readonly ?int $nextOffset,
        public readonly array $raw,
        public readonly bool $totalCapped = false,
        public readonly ?string $reference = null,
        public readonly ?string $referencePrefix = null,
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
                $items[] = CollectionItem::fromArray($item);
            }
        }
        $int = static fn (string $key): ?int => is_int($raw[$key] ?? null) ? $raw[$key] : null;

        $string = static fn (string $key): ?string => is_string($raw[$key] ?? null) ? $raw[$key] : null;

        return new self($items, $int('offset'), $int('limit'), $int('total'), $int('next_offset'), $raw,
            ($raw['total_capped'] ?? false) === true, $string('reference'), $string('reference_prefix'));
    }
}
