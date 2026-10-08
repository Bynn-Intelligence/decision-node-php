<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The tokens a decision used, as the API reported them. A request with
 * videos also reports the frames the API read and the seconds of video they
 * cover; both are null for a request without videos.
 */
final class Usage
{
    public function __construct(
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly ?int $videoFrames = null,
        public readonly int|float|null $videoSeconds = null,
    ) {
    }
}
