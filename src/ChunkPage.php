<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * One page of `collections->listChunks()`, in the document's order.
 * `nextOffset` is the offset of the next page, or null on the last page.
 */
final class ChunkPage
{
    /**
     * @param list<Chunk>             $chunks
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public readonly array $chunks,
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
        $chunks = [];
        foreach (is_array($raw['chunks'] ?? null) ? $raw['chunks'] : [] as $chunk) {
            if (is_array($chunk)) {
                $chunks[] = Chunk::fromArray($chunk);
            }
        }
        $int = static fn (string $key): ?int => is_int($raw[$key] ?? null) ? $raw[$key] : null;

        return new self($chunks, $int('offset'), $int('limit'), $int('total'), $int('next_offset'), $raw);
    }
}
