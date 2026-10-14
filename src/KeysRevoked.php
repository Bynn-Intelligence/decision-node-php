<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The answer to `keys->revokeByLabels()`: `revoked`, how many keys this call
 * revoked, or with a dry run `wouldRevoke`, how many it would (the other is
 * null), and `more`, true while matching keys are left: call again until it
 * is false.
 */
final class KeysRevoked
{
    /**
     * @param array<array-key, mixed> $raw the answer exactly as the API sent it
     */
    public function __construct(
        public readonly ?int $revoked,
        public readonly ?int $wouldRevoke,
        public readonly bool $more,
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
            is_int($raw['revoked'] ?? null) ? $raw['revoked'] : null,
            is_int($raw['would_revoke'] ?? null) ? $raw['would_revoke'] : null,
            ($raw['more'] ?? null) === true,
            $raw,
        );
    }
}
