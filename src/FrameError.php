<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * A session frame the server did not answer, as a value: the session stays
 * open. `errorType` is the API's error type (`rate_limit_error`,
 * `refusal_error`, `api_usage_error` and the others), `retryAfterMs` the wait
 * the server asks for before the next frame.
 */
final class FrameError
{
    /**
     * @param array<string, mixed>|null $usage
     * @param array<string, mixed>|null $safety
     * @param array<array-key, mixed>   $raw
     */
    public function __construct(
        public readonly ?int $seq,
        public readonly ?string $errorType,
        public readonly string $message,
        public readonly ?string $category,
        public readonly int|float|null $retryAfterMs,
        public readonly ?array $usage,
        public readonly ?array $safety,
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
        $error = is_array($raw['error'] ?? null) ? $raw['error'] : [];
        $retry = $raw['retry_after_ms'] ?? null;

        return new self(
            seq: is_int($raw['seq'] ?? null) ? $raw['seq'] : null,
            errorType: is_string($error['error_type'] ?? null) ? $error['error_type'] : null,
            message: is_string($error['message'] ?? null) ? $error['message'] : '',
            category: is_string($error['category'] ?? null) ? $error['category'] : null,
            retryAfterMs: is_int($retry) || is_float($retry) ? $retry : null,
            usage: is_array($raw['usage'] ?? null) ? $raw['usage'] : null,
            safety: is_array($raw['safety'] ?? null) ? $raw['safety'] : null,
            raw: $raw,
        );
    }
}
