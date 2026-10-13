<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The `face_recognition` booster's reading of one picture: `faces`, how many
 * faces were searched, and `matches`, by score, each face that matches an
 * item of the collection searched: its `box` (corners as sent), the match
 * `score`, the `collection`, the item's `reference`, `name` (a public
 * figure's) or `label` (an item of the workspace's own collection that has
 * one), `collection_name` (an own collection's display name or its id, or a
 * public collection's id) and `metadata` (an own item's, as sent), each null
 * when not sent. `collectionName` is an own collection's display name, null
 * when not sent.
 */
final class FaceRecognitionReading extends Reading
{
    public readonly int $faces;

    public readonly ?string $collectionName;

    /** @var list<array{box: array{x1: int|float, y1: int|float, x2: int|float, y2: int|float}, score: int|float, collection: string, reference: string, name: ?string, label: ?string, collection_name: ?string, metadata: ?array<array-key, mixed>}> */
    public readonly array $matches;

    /**
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function wellFormed(array $raw): bool
    {
        if (!is_string($raw['image'] ?? null) || !is_int($raw['faces'] ?? null) || $raw['faces'] < 0 || !is_array($raw['matches'] ?? null) || !array_is_list($raw['matches'])
            || (($raw['collection_name'] ?? null) !== null && !is_string($raw['collection_name']))) {
            return false;
        }
        foreach ($raw['matches'] as $match) {
            if (!self::match($match)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether one match is whole: a box, a numeric score, the collection and
     * the reference as strings, name, label and collection_name strings and
     * metadata an object when sent.
     */
    private static function match(mixed $match): bool
    {
        return is_array($match)
            && self::box($match['box'] ?? null)
            && self::isNumber($match['score'] ?? null)
            && is_string($match['collection'] ?? null)
            && is_string($match['reference'] ?? null)
            && (($match['name'] ?? null) === null || is_string($match['name']))
            && (($match['label'] ?? null) === null || is_string($match['label']))
            && (($match['collection_name'] ?? null) === null || is_string($match['collection_name']))
            && (($match['metadata'] ?? null) === null || (is_array($match['metadata']) && ($match['metadata'] === [] || !array_is_list($match['metadata']))));
    }

    /**
     * @param array<array-key, mixed> $raw a well-formed face_recognition reading
     */
    public function __construct(array $raw)
    {
        parent::__construct((string) ($raw['image'] ?? ''), $raw);
        $this->faces = is_int($raw['faces'] ?? null) ? $raw['faces'] : 0;
        $this->collectionName = is_string($raw['collection_name'] ?? null) ? $raw['collection_name'] : null;
        $matches = [];
        foreach (is_array($raw['matches'] ?? null) ? $raw['matches'] : [] as $match) {
            if (self::match($match) && is_array($match)) {
                /** @var array{x1: int|float, y1: int|float, x2: int|float, y2: int|float} $box */
                $box = $match['box'];
                /** @var int|float $score */
                $score = $match['score'];
                $matches[] = [
                    'box' => ['x1' => $box['x1'], 'y1' => $box['y1'], 'x2' => $box['x2'], 'y2' => $box['y2']],
                    'score' => $score,
                    'collection' => (string) $match['collection'],
                    'reference' => (string) $match['reference'],
                    'name' => is_string($match['name'] ?? null) ? $match['name'] : null,
                    'label' => is_string($match['label'] ?? null) ? $match['label'] : null,
                    'collection_name' => is_string($match['collection_name'] ?? null) ? $match['collection_name'] : null,
                    'metadata' => is_array($match['metadata'] ?? null) ? $match['metadata'] : null,
                ];
            }
        }
        $this->matches = $matches;
    }
}
