<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The tokens a decision used, as the API reported them. A request with
 * videos also reports the frames the API read and the seconds of video they
 * cover; both are null for a request without videos. A request with boosters
 * reports `boosterTokens`, the readings' share of `inputTokens`, and
 * `boosters`, the booster calls billed; both are null for a request without
 * boosters. `cost` is what the answer cost in USD, as the API sent it; null
 * when it sent none.
 */
final class Usage
{
    public function __construct(
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly ?int $videoFrames = null,
        public readonly int|float|null $videoSeconds = null,
        public readonly ?int $boosterTokens = null,
        public readonly ?int $boosters = null,
        public readonly ?Cost $cost = null,
    ) {
    }
}
