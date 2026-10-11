<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The `wanted_person` booster's reading of one picture: `faces`, how many
 * faces were checked, and `matches`, each face that matches an entry of the
 * list: its `box` (corners as sent), the match `score` and `reference`, the
 * entry's reference id, never a name or any personal detail.
 */
final class WantedPersonReading extends Reading
{
    public readonly int $faces;

    /** @var list<array{box: array{x1: int|float, y1: int|float, x2: int|float, y2: int|float}, score: int|float, reference: string}> */
    public readonly array $matches;

    /**
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function wellFormed(array $raw): bool
    {
        if (!is_string($raw['image'] ?? null) || !is_int($raw['faces'] ?? null) || $raw['faces'] < 0 || !is_array($raw['matches'] ?? null) || !array_is_list($raw['matches'])) {
            return false;
        }
        foreach ($raw['matches'] as $match) {
            if (!is_array($match) || !self::box($match['box'] ?? null) || !self::isNumber($match['score'] ?? null) || !is_string($match['reference'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $raw a well-formed wanted_person reading
     */
    public function __construct(array $raw)
    {
        parent::__construct((string) ($raw['image'] ?? ''), $raw);
        $this->faces = is_int($raw['faces'] ?? null) ? $raw['faces'] : 0;
        $matches = [];
        foreach (is_array($raw['matches'] ?? null) ? $raw['matches'] : [] as $match) {
            if (is_array($match) && self::box($match['box'] ?? null) && self::isNumber($match['score'] ?? null) && is_string($match['reference'] ?? null)) {
                /** @var array{x1: int|float, y1: int|float, x2: int|float, y2: int|float} $box */
                $box = $match['box'];
                $matches[] = ['box' => ['x1' => $box['x1'], 'y1' => $box['y1'], 'x2' => $box['x2'], 'y2' => $box['y2']], 'score' => $match['score'], 'reference' => $match['reference']];
            }
        }
        $this->matches = $matches;
    }
}
