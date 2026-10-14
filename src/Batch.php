<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * A batch: every field of the batch object as the API sent it.
 *
 * `state` is one of `draft`, `queued`, `running`, `completed`, `failed`,
 * `expired` or `cancelled`; `endedAt` is set once the batch has ended.
 *
 * A batch states no price: the batch price is each model's
 * `batchPriceFactor` in `models()` (MoE 0.7, Flash 0.5, none on a fine-tuned
 * model), and each line's charge is in its usage.
 */
final class Batch
{
    /**
     * @param array<string, int>        $counters requests, queued, in_progress, completed, failed, expired, cancelled
     * @param array<string, mixed>|null $usage    input_tokens and output_tokens
     * @param array<string, mixed>|null $error    why a failed batch failed
     * @param array<array-key, mixed>   $raw      the batch object exactly as the API sent it
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $state,
        public readonly array $counters,
        public readonly ?string $createdAt,
        public readonly ?string $finalizedAt,
        public readonly ?string $expiresAt,
        public readonly ?string $endedAt,
        public readonly ?string $resultsUrl,
        public readonly ?string $resultsExpireAt,
        public readonly ?array $usage,
        public readonly ?array $error,
        public readonly array $raw,
    ) {
    }

    /**
     * Whether the batch has ended (completed, failed, expired or cancelled).
     */
    public function ended(): bool
    {
        return $this->endedAt !== null;
    }

    /**
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $string = static fn (string $key): ?string => is_string($raw[$key] ?? null) ? $raw[$key] : null;
        $counters = [];
        foreach (is_array($raw['counters'] ?? null) ? $raw['counters'] : [] as $name => $count) {
            if (is_int($count)) {
                $counters[(string) $name] = $count;
            }
        }

        return new self(
            id: $string('id'),
            state: $string('state'),
            counters: $counters,
            createdAt: $string('created_at'),
            finalizedAt: $string('finalized_at'),
            expiresAt: $string('expires_at'),
            endedAt: $string('ended_at'),
            resultsUrl: $string('results_url'),
            resultsExpireAt: $string('results_expire_at'),
            usage: is_array($raw['usage'] ?? null) ? $raw['usage'] : null,
            error: is_array($raw['error'] ?? null) ? $raw['error'] : null,
            raw: $raw,
        );
    }
}
