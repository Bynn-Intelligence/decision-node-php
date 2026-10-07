<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The tokens a decision used, as the API reported them.
 */
final class Usage
{
    public function __construct(
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
    ) {
    }
}
