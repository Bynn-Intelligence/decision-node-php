<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Exception;

/**
 * No response within the client's timeout. Retried before it is raised.
 */
class TimeoutError extends DecisionNodeError
{
}
