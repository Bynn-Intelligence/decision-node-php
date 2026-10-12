<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * One answer of a decision.
 *
 * Known question types come back as {@see TruthAnswer}, {@see ChoiceAnswer},
 * {@see ScoreAnswer}, {@see NumberAnswer}, {@see PointAnswer} or
 * {@see BoxAnswer}, and a point or box question asked with `max` as
 * {@see PointsAnswer} or {@see BoxesAnswer}, told by the answer's own list
 * of places. An answer of a type this version of the SDK does not
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

        // Several places: a point or box answer carrying a list of places is
        // read by that list, and is well formed only with its type's own list.
        if (($type === 'point' || $type === 'box') && (array_key_exists('points', $raw) || array_key_exists('boxes', $raw))) {
            return match (true) {
                $type === 'point' && PointsAnswer::wellFormed($raw, $shape) => new PointsAnswer($raw),
                $type === 'box' && BoxesAnswer::wellFormed($raw, $shape) => new BoxesAnswer($raw),
                default => new UnknownAnswer($type, $raw),
            };
        }

        // A known type whose fields are missing or of the wrong type is
        // returned like an unknown type, with its raw fields: one odd answer
        // does not fail the whole decision.
        // An answer under the truth type's older name, which the API sends
        // for a question sent under that name, is read into a TruthAnswer.
        return match (true) {
            $type === 'truth' && TruthAnswer::wellFormed($raw) => new TruthAnswer($raw),
            $type === 'noul' && TruthAnswer::wellFormed($raw, 'noul') => new TruthAnswer($raw, 'noul'),
            $type === 'number' && NumberAnswer::wellFormed($raw) && self::objects($shape, 'probabilities') => new NumberAnswer($raw),
            $type === 'choice' && ChoiceAnswer::wellFormed($raw) && self::objects($shape, 'probabilities') => new ChoiceAnswer($raw),
            $type === 'score' && ScoreAnswer::wellFormed($raw) && self::objects($shape, 'probabilities', 'legend') => new ScoreAnswer($raw),
            $type === 'point' && PointAnswer::wellFormed($raw) => new PointAnswer($raw),
            $type === 'box' && BoxAnswer::wellFormed($raw) => new BoxAnswer($raw),
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
     * Whether `$raw` is a well-formed several-places answer: `count` a whole
     * number of 0 or more; `present`, `complete`, `width` and `height`
     * numbers; `image` a string; and `$list` a JSON array of JSON objects,
     * each with every named field a number.
     *
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    protected static function placesWellFormed(array $raw, ?\stdClass $shape, string $list, string ...$fields): bool
    {
        if (!is_int($raw['count'] ?? null) || $raw['count'] < 0 || !self::numbers($raw, 'present', 'complete', 'width', 'height') || !is_string($raw['image'] ?? null)) {
            return false;
        }
        $places = $raw[$list] ?? null;
        if (!is_array($places) || !array_is_list($places)) {
            return false;
        }
        $shapes = $shape->{$list} ?? null;
        if ($shape !== null && !is_array($shapes)) {
            return false;
        }
        foreach ($places as $i => $place) {
            if (!is_array($place) || !self::numbers($place, ...$fields)) {
                return false;
            }
            if (is_array($shapes) && !($shapes[$i] ?? null) instanceof \stdClass) {
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
     * Whether every named field of `$raw` is a JSON number.
     *
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    protected static function numbers(array $raw, string ...$fields): bool
    {
        foreach ($fields as $field) {
            if (!self::isNumber($raw[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A field known to be a number.
     *
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    protected static function required(array $raw, string $field): int|float
    {
        $value = $raw[$field] ?? null;

        return self::isNumber($value) ? $value : 0;
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
