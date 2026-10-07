<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Exception;

/**
 * HTTP 400: the server could not use the request as sent. `reason` says what to change.
 */
class BadRequestError extends DecisionNodeError
{
}
