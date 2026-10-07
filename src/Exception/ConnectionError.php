<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Exception;

/**
 * No response: the connection could not be made or was dropped. Retried before it is raised.
 */
class ConnectionError extends DecisionNodeError
{
}
