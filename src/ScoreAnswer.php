<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The answer to a `score` question: the expected level (a number between 0 and
 * the highest level index), the confidence, the probability of each level by
 * its index, and the legend mapping each index back to the level as sent.
 */
final class ScoreAnswer extends Answer
{
    public readonly int|float|null $score;

    public readonly int|float|null $confidence;

    /** @var array<int, int|float> */
    public readonly array $probabilities;

    /** @var array<int, mixed> */
    public readonly array $legend;

    /**
     * Whether `$raw` has the fields of a score answer with the right types:
     * `probabilities` and `legend` keyed by level index.
     *
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function wellFormed(array $raw): bool
    {
        if (!self::isNumber($raw['score'] ?? null) || !self::isNumber($raw['confidence'] ?? null)) {
            return false;
        }
        $probabilities = $raw['probabilities'] ?? null;
        $legend = $raw['legend'] ?? null;
        if (!self::isNumberMap($probabilities) || !is_array($legend)) {
            return false;
        }
        foreach ([...array_keys($probabilities), ...array_keys($legend)] as $index) {
            if (!is_int($index) || $index < 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct('score', $raw);
        $this->score = self::number($raw['score'] ?? null);
        $this->confidence = self::number($raw['confidence'] ?? null);

        $probabilities = [];
        foreach (is_array($raw['probabilities'] ?? null) ? $raw['probabilities'] : [] as $index => $p) {
            $number = self::number($p);
            if ($number !== null) {
                $probabilities[(int) $index] = $number;
            }
        }
        ksort($probabilities);
        $this->probabilities = $probabilities;

        $legend = [];
        foreach (is_array($raw['legend'] ?? null) ? $raw['legend'] : [] as $index => $level) {
            $legend[(int) $index] = $level;
        }
        ksort($legend);
        $this->legend = $legend;
    }
}
