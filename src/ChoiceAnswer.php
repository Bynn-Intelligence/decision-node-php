<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The answer to a `choice` question: the chosen option, the confidence, and the
 * probability of every option, keyed by the option name exactly as sent.
 *
 * PHP stores an array key that looks like a decimal integer ("7") as an
 * integer; reading `$answer->probabilities[$answer->choice]` works either way.
 */
final class ChoiceAnswer extends Answer
{
    public readonly ?string $choice;

    public readonly int|float|null $confidence;

    /** @var array<array-key, int|float> */
    public readonly array $probabilities;

    /**
     * Whether `$raw` has the fields of a choice answer with the right types.
     *
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function wellFormed(array $raw): bool
    {
        return is_string($raw['choice'] ?? null)
            && self::isNumber($raw['confidence'] ?? null)
            && self::isNumberMap($raw['probabilities'] ?? null);
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct('choice', $raw);
        $choice = $raw['choice'] ?? null;
        $this->choice = is_scalar($choice) ? (string) $choice : null;
        $this->confidence = self::number($raw['confidence'] ?? null);
        $probabilities = [];
        foreach (is_array($raw['probabilities'] ?? null) ? $raw['probabilities'] : [] as $name => $p) {
            $number = self::number($p);
            if ($number !== null) {
                $probabilities[$name] = $number;
            }
        }
        $this->probabilities = $probabilities;
    }
}
