<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Exception;

/**
 * HTTP 429 with `session_limit_exceeded`: the key already holds as many open
 * sessions as its plan allows. Never retried: waiting does not free a
 * session, ending one does. A subclass of {@see RateLimitError}, so code that
 * catches rate limits catches this too.
 */
class SessionLimitError extends RateLimitError
{
}
