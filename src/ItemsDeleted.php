<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The answer to `collections->deleteFailed()`: how many failed
 * documents were `deleted` (null when the API sent no count).
 */
final class ItemsDeleted
{
    /**
     * @param array<array-key, mixed> $raw the answer exactly as the API sent it
     */
    public function __construct(
        public readonly ?int $deleted,
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
        return new self(is_int($raw['deleted'] ?? null) ? $raw['deleted'] : null, $raw);
    }
}
