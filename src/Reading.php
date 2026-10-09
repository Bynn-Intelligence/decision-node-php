<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * One booster's reading of one image, named by `image`.
 *
 * The `age` booster's readings are {@see AgeReading}s and the `ai_generated`
 * booster's {@see AiGeneratedReading}s. A reading of a booster this version of
 * the SDK does not know, or one with a field missing or of the wrong type, is
 * an {@see UnknownReading} holding its raw fields: the decision does not fail.
 */
abstract class Reading
{
    /**
     * @param array<array-key, mixed> $raw the reading exactly as the API sent it
     */
    public function __construct(
        public readonly ?string $image,
        public readonly array $raw,
    ) {
    }

    /**
     * @internal
     */
    public static function fromArray(string $booster, mixed $raw): Reading
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            return new UnknownReading(is_array($raw) ? $raw : []);
        }

        return match (true) {
            $booster === Booster::AGE && AgeReading::wellFormed($raw) => new AgeReading($raw),
            $booster === Booster::AI_GENERATED && AiGeneratedReading::wellFormed($raw) => new AiGeneratedReading($raw),
            default => new UnknownReading($raw),
        };
    }

    /**
     * @internal
     */
    protected static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
