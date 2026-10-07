<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Exception;

/**
 * HTTP 401, or no API key was configured (then `status` is null and nothing was sent).
 */
class AuthenticationError extends DecisionNodeError
{
}
