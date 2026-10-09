<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Exception;

/**
 * HTTP 413: the request body is over 25 MiB (26,214,400 bytes). The SDK
 * refuses such a body itself before anything is sent, with this error and no
 * status. Never retried.
 */
class RequestTooLargeError extends DecisionNodeError
{
}
