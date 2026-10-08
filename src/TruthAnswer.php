<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The answer to a `truth` question: the probability, from 0 to 1, that the
 * statement is true.
 */
final class TruthAnswer extends Answer
{
    public readonly int|float|null $truth;

    /**
     * Whether `$raw` has the fields of a truth answer with the right types.
     *
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function wellFormed(array $raw, string $field = 'truth'): bool
    {
        return self::isNumber($raw[$field] ?? null);
    }

    /**
     * @param array<array-key, mixed> $raw
     * @param string                  $field the field holding the value: `truth`, or the type's older name
     */
    public function __construct(array $raw, string $field = 'truth')
    {
        parent::__construct('truth', $raw);
        $this->truth = self::number($raw[$field] ?? null);
    }
}
