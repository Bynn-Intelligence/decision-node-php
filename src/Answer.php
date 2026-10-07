<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * One answer of a decision.
 *
 * Known question types come back as {@see TruthAnswer}, {@see ChoiceAnswer} or
 * {@see ScoreAnswer}. An answer of a type this version of the SDK does not
 * know comes back as an {@see UnknownAnswer}, holding its type and raw fields,
 * so a new question type on the server does not break an older client.
 */
abstract class Answer
{
    /**
     * @param array<array-key, mixed> $raw the answer exactly as the API sent it
     */
    public function __construct(
        public readonly ?string $type,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<array-key, mixed> $raw
     * @param \stdClass|null          $shape the answer decoded with JSON objects kept as \stdClass, when known
     */
    public static function fromArray(array $raw, ?\stdClass $shape = null): Answer
    {
        $type = isset($raw['type']) && is_string($raw['type']) ? $raw['type'] : null;

        // A known type whose fields are missing or of the wrong type is
        // returned like an unknown type, with its raw fields: one odd answer
        // does not fail the whole decision.
        return match (true) {
            $type === 'truth' && TruthAnswer::wellFormed($raw) => new TruthAnswer($raw),
            $type === 'choice' && ChoiceAnswer::wellFormed($raw) && self::objects($shape, 'probabilities') => new ChoiceAnswer($raw),
            $type === 'score' && ScoreAnswer::wellFormed($raw) && self::objects($shape, 'probabilities', 'legend') => new ScoreAnswer($raw),
            default => new UnknownAnswer($type, $raw),
        };
    }

    /**
     * Whether each named field is a JSON object (not a list) in `$shape`;
     * true when the shape is not known.
     */
    private static function objects(?\stdClass $shape, string ...$fields): bool
    {
        if ($shape === null) {
            return true;
        }
        foreach ($fields as $field) {
            if (!($shape->{$field} ?? null) instanceof \stdClass) {
                return false;
            }
        }

        return true;
    }

    /**
     * @internal
     */
    protected static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    /**
     * A JSON object (or empty) whose values are all numbers.
     *
     * @internal
     */
    protected static function isNumberMap(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $number) {
            if (!self::isNumber($number)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @internal
     */
    protected static function number(mixed $value): int|float|null
    {
        return self::isNumber($value) ? $value : null;
    }
}
