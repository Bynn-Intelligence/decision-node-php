<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The `age` booster's reading of one picture. The largest face is read:
 * `ageYears` is its age, `low` and `high` the range, in years, and
 * `confidence` 0 to 1. `faces` is how many faces were found; with 0 the
 * picture shows no face and the age fields are null. Every number is the
 * JSON number the API sent.
 */
final class AgeReading extends Reading
{
    public readonly int $faces;

    public readonly int|float|null $ageYears;

    public readonly int|float|null $low;

    public readonly int|float|null $high;

    public readonly int|float|null $confidence;

    /**
     * Whether `$raw` is a well-formed age reading: `image` and `faces`, and
     * the four age fields as numbers when a face was found.
     *
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function wellFormed(array $raw): bool
    {
        if (!is_string($raw['image'] ?? null) || !is_int($raw['faces'] ?? null)) {
            return false;
        }
        foreach (['age_years', 'low', 'high', 'confidence'] as $field) {
            $value = $raw[$field] ?? null;
            if ($value === null ? $raw['faces'] > 0 : !self::isNumber($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $raw a well-formed age reading
     */
    public function __construct(array $raw)
    {
        parent::__construct((string) ($raw['image'] ?? ''), $raw);
        $this->faces = is_int($raw['faces'] ?? null) ? $raw['faces'] : 0;
        $this->ageYears = self::number($raw, 'age_years');
        $this->low = self::number($raw, 'low');
        $this->high = self::number($raw, 'high');
        $this->confidence = self::number($raw, 'confidence');
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private static function number(array $raw, string $field): int|float|null
    {
        $value = $raw[$field] ?? null;

        return self::isNumber($value) ? $value : null;
    }
}
