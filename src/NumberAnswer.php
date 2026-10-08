<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The answer to a `number` question: the most likely value of the grid, the
 * expected value, the probability of that value, and the probability of each
 * likely value keyed by the value exactly as the API wrote it ("7", "3.5").
 *
 * Every number is the JSON number the API sent, with no rounding. PHP stores
 * an array key that looks like a decimal integer ("7") as an integer, so read
 * a whole value as `$answer->probabilities[7]` or `$answer->probabilities['7']`.
 */
final class NumberAnswer extends Answer
{
    public readonly int|float|null $number;

    public readonly int|float|null $expected;

    public readonly int|float|null $confidence;

    /** @var array<array-key, int|float> */
    public readonly array $probabilities;

    /**
     * Whether `$raw` has the fields of a number answer with the right types.
     *
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function wellFormed(array $raw): bool
    {
        return self::isNumber($raw['number'] ?? null)
            && self::isNumber($raw['expected'] ?? null)
            && self::isNumber($raw['confidence'] ?? null)
            && self::isNumberMap($raw['probabilities'] ?? null);
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct('number', $raw);
        $this->number = self::number($raw['number'] ?? null);
        $this->expected = self::number($raw['expected'] ?? null);
        $this->confidence = self::number($raw['confidence'] ?? null);
        $probabilities = [];
        foreach (is_array($raw['probabilities'] ?? null) ? $raw['probabilities'] : [] as $value => $p) {
            $number = self::number($p);
            if ($number !== null) {
                $probabilities[$value] = $number;
            }
        }
        $this->probabilities = $probabilities;
    }
}
