<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * What one booster read for a request.
 *
 * `billedCalls` is the calls of that booster billed on this request: one per
 * image read with status `ok`, 0 for a booster that timed out or failed; null
 * when the API sent none.
 *
 * `status` is `ok`, `timeout` (no answer within the booster's time), `error`
 * or `skipped` (none of the request's images is one it reads), or any other
 * value as the API sent it. `results` holds one reading per picture the
 * booster was to read: an {@see AgeReading}, an {@see AiGeneratedReading}, or
 * an {@see UnknownReading} for a booster this version does not know or a
 * reading with a field missing or of the wrong type. A picture the booster
 * did not read (it timed out or failed: `{"image"}`) or could not decode
 * (`{"image", "readable": false}`) is an UnknownReading, and `status` says why.
 */
final class Evidence
{
    /**
     * @param list<Reading>           $results
     * @param array<array-key, mixed> $raw     the evidence exactly as the API sent it
     */
    public function __construct(
        public readonly string $booster,
        public readonly ?string $version,
        public readonly ?string $status,
        public readonly int|float|null $latencyMs,
        public readonly array $results,
        public readonly array $raw,
        public readonly ?int $billedCalls = null,
    ) {
    }

    /**
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function fromArray(string $booster, array $raw): self
    {
        $latency = $raw['latency_ms'] ?? null;
        $results = [];
        foreach (is_array($raw['results'] ?? null) ? $raw['results'] : [] as $reading) {
            $results[] = Reading::fromArray($booster, $reading);
        }

        return new self(
            booster: $booster,
            version: is_string($raw['version'] ?? null) ? $raw['version'] : null,
            status: is_string($raw['status'] ?? null) ? $raw['status'] : null,
            latencyMs: is_int($latency) || is_float($latency) ? $latency : null,
            results: $results,
            raw: $raw,
            billedCalls: is_int($raw['billed_calls'] ?? null) ? $raw['billed_calls'] : null,
        );
    }
}
