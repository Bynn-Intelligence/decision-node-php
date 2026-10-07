<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Exception;

/**
 * HTTP 500 to 599 (other than 529), retried before it is raised; a success response whose body is not
 * JSON; or a success response where the answer of check(), classify() or score() is missing or malformed.
 */
class ServerError extends DecisionNodeError
{
}
