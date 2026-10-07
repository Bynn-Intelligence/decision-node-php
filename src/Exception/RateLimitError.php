<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Exception;

/**
 * HTTP 429: too many requests. Retried before it is raised. `retryAfter` is the wait the
 * server asked for, in seconds (from `retry-after-ms`, else `Retry-After`),
 * or null when it sent neither.
 */
class RateLimitError extends DecisionNodeError
{
    public function __construct(
        string $message = '',
        public readonly ?float $retryAfter = null,
        mixed ...$fields,
    ) {
        parent::__construct($message, ...$fields);
    }
}
