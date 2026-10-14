<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The answer to `keys->revoke()`: the key's `id` and `revoked` true. The
 * next call with that key gets a 401.
 */
final class RevokedKey
{
    /**
     * @param array<array-key, mixed> $raw the answer exactly as the API sent it
     */
    public function __construct(
        public readonly ?string $id,
        public readonly bool $revoked,
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
        return new self(is_string($raw['id'] ?? null) ? $raw['id'] : null, ($raw['revoked'] ?? null) === true, $raw);
    }
}
