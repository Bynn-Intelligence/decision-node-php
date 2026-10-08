<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * One page of `batches->list()`, newest first. `nextOffset` is the offset of
 * the next page, or null on the last page.
 */
final class BatchPage
{
    /**
     * @param list<Batch>             $batches
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public readonly array $batches,
        public readonly ?int $offset,
        public readonly ?int $limit,
        public readonly ?int $total,
        public readonly ?int $nextOffset,
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
        $batches = [];
        foreach (is_array($raw['batches'] ?? null) ? $raw['batches'] : [] as $batch) {
            if (is_array($batch)) {
                $batches[] = Batch::fromArray($batch);
            }
        }
        $int = static fn (string $key): ?int => is_int($raw[$key] ?? null) ? $raw[$key] : null;

        return new self($batches, $int('offset'), $int('limit'), $int('total'), $int('next_offset'), $raw);
    }
}
