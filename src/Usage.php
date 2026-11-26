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
 *
 * `imageTokens` and `videoTokens` are the shares of `inputTokens` that came
 * from images and from videos, read from the `x-decisionnode-image-tokens`
 * and `x-decisionnode-video-tokens` headers as sent: never added to or taken
 * from another count, never priced. Each is null for a request without
 * images or videos (the server sends a header only when its share is above
 * 0), and when its header is not a whole number from 0 to 2^31 - 1.
 */
final class Usage
{
    /** The largest token count a header may carry, 2^31 - 1. */
    private const MAX_HEADER_TOKENS = 2147483647;

    public function __construct(
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly ?int $videoFrames = null,
        public readonly int|float|null $videoSeconds = null,
        public readonly ?int $boosterTokens = null,
        public readonly ?int $boosters = null,
        public readonly ?Cost $cost = null,
        public readonly ?int $imageTokens = null,
        public readonly ?int $videoTokens = null,
    ) {
    }

    /**
     * A token count from a header: digits only after trimming spaces, from 0
     * to 2^31 - 1. Anything else (absent, empty, a sign, a fraction, too
     * large) is null.
     *
     * @internal
     */
    public static function tokenHeader(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $digits = trim($value);
        if (preg_match('/\A[0-9]+\z/', $digits) !== 1) {
            return null;
        }
        // Leading zeros dropped first, so a long run of digits cannot overflow.
        $significant = ltrim($digits, '0');
        if (strlen($significant) > 10) {
            return null;
        }
        $n = (int) $significant;

        return $n <= self::MAX_HEADER_TOKENS ? $n : null;
    }
}
