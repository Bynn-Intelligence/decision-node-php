<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * A collection: one of the workspace's own collections of faces (type
 * `face`) or of documents (type `text`, a knowledge base), or a public one
 * it may search, every field as the API sent it.
 *
 * `id` is `col_` and 32 hex digits for the workspace's own collection; a
 * public collection's id is its name, such as `celebrities`. `public`
 * collections are read-only. `deletion` is set while the workspace's items
 * are marked for deletion: `{at, reason}`.
 *
 * A knowledge base also carries `scopeKey` (the metadata key that scopes
 * its items, or null), `chunks` and `tokens` (what search can see),
 * `version` (the corpus version, raised by every change a search can see),
 * `statusCounts` (its documents by status: `queued`, `processing`, `ready`,
 * `failed`) and `pinned` (the pinned load: `tokens`, `cap` and
 * `largest_scope`, `{value, tokens}` or null). A face collection has none
 * of them: each is null.
 */
final class Collection
{
    /**
     * @param array<string, mixed>|null $deletion `at` and `reason`, or null
     * @param array<array-key, mixed>   $raw      the collection object exactly as the API sent it
     * @param array<array-key, mixed>|null $statusCounts `queued`, `processing`, `ready` and `failed`, as sent
     * @param array<array-key, mixed>|null $pinned       `tokens`, `cap` and `largest_scope`, as sent
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
        public readonly ?string $scopeKey = null,
        public readonly ?int $chunks = null,
        public readonly ?int $tokens = null,
        public readonly ?int $version = null,
        public readonly ?array $statusCounts = null,
        public readonly ?array $pinned = null,
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
        $int = static fn (string $key): ?int => is_int($raw[$key] ?? null) ? $raw[$key] : null;
        $map = static fn (string $key): ?array => is_array($raw[$key] ?? null) ? $raw[$key] : null;

        return new self(
            id: $string('id'),
            type: $string('type'),
            name: $string('name'),
            public: is_bool($raw['public'] ?? null) ? $raw['public'] : null,
            items: $int('items'),
            createdAt: $string('created_at'),
            deletion: is_array($raw['deletion'] ?? null) ? $raw['deletion'] : null,
            raw: $raw,
            scopeKey: $string('scope_key'),
            chunks: $int('chunks'),
            tokens: $int('tokens'),
            version: $int('version'),
            statusCounts: $map('status_counts'),
            pinned: $map('pinned'),
        );
    }
}
