<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The answer to `keys->create()`: the new `key` and its `secret`, which the
 * API shows only here (and to a retry with the same Idempotency-Key within
 * 24 hours). Hand the secret to its device once; never log it.
 */
final class CreatedKey
{
    /**
     * @param array<array-key, mixed> $raw the answer exactly as the API sent it
     */
    public function __construct(
        public readonly Key $key,
        public readonly ?string $secret,
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
        return new self(
            Key::fromArray(is_array($raw['key'] ?? null) ? $raw['key'] : []),
            is_string($raw['secret'] ?? null) ? $raw['secret'] : null,
            $raw,
        );
    }

    /**
     * Never prints the secret.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['key' => $this->key, 'secret' => $this->secret === null ? null : '[hidden]'];
    }
}
