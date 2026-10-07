<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * One result of `decideMany`: a {@see Decision}, or the
 * {@see Exception\DecisionNodeError} that request ended with. Tell them apart
 * with `instanceof`.
 */
interface DecisionResult
{
}
