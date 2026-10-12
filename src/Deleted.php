<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The answer to a delete: the `id` deleted and `deleted` true.
 */
final class Deleted
{
    /**
     * @param array<array-key, mixed> $raw the answer exactly as the API sent it
     */
    public function __construct(
        public readonly ?string $id,
        public readonly bool $deleted,
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
        return new self(is_string($raw['id'] ?? null) ? $raw['id'] : null, ($raw['deleted'] ?? null) === true, $raw);
    }
}
