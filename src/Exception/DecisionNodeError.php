<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Exception;

use Bynn\DecisionNode\DecisionResult;

/**
 * The base of every error this SDK raises, and the error for any HTTP status
 * no subclass covers (a 3xx, 405, 409, or 408 once its retries are used up).
 *
 * For an error the API returned, `getMessage()` is the server's message (the
 * body's `detail.message`, or `detail` when it is a string; empty when the
 * server sent none), and the fields hold the rest of what the server sent:
 * `status` (the HTTP status), `errorType` (the body's `detail.error_type`),
 * `requestId` (the `x-request-id` header) and `reason` (the
 * `x-decisionnode-error` header, the server's explanation in words). An error
 * of a request that named boosters carries `boosters`: how each booster ended,
 * from the `x-decisionnode-boosters` header, by name in the request's order
 * (`['age' => ['status' => 'timeout', 'latency_ms' => 800]]`), so a caller
 * sees which required booster failed; empty when the header is absent. For an
 * error raised before any response (no API key, a connection that failed),
 * `getMessage()` describes it and `status` is null. A bad argument is PHP's
 * own \InvalidArgumentException, not this class.
 */
class DecisionNodeError extends \RuntimeException implements DecisionResult
{
    /**
     * @param array<string, string> $headers  the response headers, names in lower case
     * @param array<string, array{status: string, latency_ms: int|float|null}> $boosters by booster name, in the request's order
     */
    public function __construct(
        string $message = '',
        public readonly ?int $status = null,
        public readonly ?string $errorType = null,
        public readonly ?string $requestId = null,
        public readonly ?string $reason = null,
        public readonly mixed $body = null,
        public readonly array $headers = [],
        public readonly array $boosters = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
