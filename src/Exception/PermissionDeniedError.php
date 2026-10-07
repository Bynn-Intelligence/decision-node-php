<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Exception;

/**
 * HTTP 403 that is not a refusal: the key may not use this resource.
 */
class PermissionDeniedError extends DecisionNodeError
{
}
