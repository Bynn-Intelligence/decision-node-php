<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * A collection: one of the workspace's own collections of faces, or a public
 * one it may search, every field as the API sent it.
 *
 * `id` is `col_` and 32 hex digits for the workspace's own collection; a
 * public collection's id is its name, such as `celebrities`. `public`
 * collections are read-only. `deletion` is set while the workspace's items
 * are marked for deletion: `{at, reason}`.
 */
final class Collection
{
    /**
     * @param array<string, mixed>|null $deletion `at` and `reason`, or null
     * @param array<array-key, mixed>   $raw      the collection object exactly as the API sent it
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $type,
        public readonly ?string $name,
        public readonly ?bool $public,
        public readonly ?int $items,
        public readonly ?string $createdAt,
        public readonly ?array $deletion,
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

        return new self(
            id: $string('id'),
            type: $string('type'),
            name: $string('name'),
            public: is_bool($raw['public'] ?? null) ? $raw['public'] : null,
            items: is_int($raw['items'] ?? null) ? $raw['items'] : null,
            createdAt: $string('created_at'),
            deletion: is_array($raw['deletion'] ?? null) ? $raw['deletion'] : null,
            raw: $raw,
        );
    }
}
