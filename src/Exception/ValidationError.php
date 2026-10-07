<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Exception;

/**
 * HTTP 422: the body does not have the shape the API expects. `entries` is the
 * server's list of problems, each with `type`, `loc` and `msg`.
 */
class ValidationError extends DecisionNodeError
{
    /**
     * @param array<mixed>|null $entries
     */
    public function __construct(
        string $message = '',
        public readonly ?array $entries = null,
        mixed ...$fields,
    ) {
        parent::__construct($message, ...$fields);
    }
}
