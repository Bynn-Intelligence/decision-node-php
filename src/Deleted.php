<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The answer to a delete: the `id` deleted and `deleted` true. A knowledge
 * base still being purged after 10 s answers `status` `purging` instead
 * (and `deleted` false), the purge finishing in the background; both are
 * success. `status` is null when the API sent none.
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
        public readonly ?string $status = null,
    ) {
    }

    /**
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(is_string($raw['id'] ?? null) ? $raw['id'] : null, ($raw['deleted'] ?? null) === true, $raw,
            is_string($raw['status'] ?? null) ? $raw['status'] : null);
    }
}
