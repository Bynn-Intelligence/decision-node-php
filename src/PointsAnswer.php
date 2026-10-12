<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The answer to a `point` question asked with `max`: up to `max` spots in an
 * image. `count` is how many it found and `points` the spots, left to right
 * by their position (ties from the top), each its `x`, `y` and `confidence`
 * in the question's units.
 *
 * `present` is the probability that what the instructions describe is in the
 * image at all, and `complete` the probability that `points` holds every one
 * of them. `image`, `width` and `height` name the image looked in and its
 * size in pixels. Every number is the JSON number the API sent.
 */
final class PointsAnswer extends Answer
{
    public readonly int $count;

    public readonly int|float $present;

    public readonly int|float $complete;

    /** @var list<array{x: int|float, y: int|float, confidence: int|float}> */
    public readonly array $points;

    public readonly string $image;

    public readonly int|float $width;

    public readonly int|float $height;

    /**
     * Whether `$raw` has every field of a several-points answer with the
     * right types.
     *
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function wellFormed(array $raw, ?\stdClass $shape = null): bool
    {
        return self::placesWellFormed($raw, $shape, 'points', 'x', 'y', 'confidence');
    }

    /**
     * @param array<array-key, mixed> $raw a well-formed several-points answer
     */
    public function __construct(array $raw)
    {
        parent::__construct('point', $raw);
        $this->count = is_int($raw['count'] ?? null) ? $raw['count'] : 0;
        $this->present = self::required($raw, 'present');
        $this->complete = self::required($raw, 'complete');
        $points = [];
        foreach (is_array($raw['points'] ?? null) ? $raw['points'] : [] as $point) {
            if (is_array($point)) {
                $points[] = ['x' => self::required($point, 'x'), 'y' => self::required($point, 'y'), 'confidence' => self::required($point, 'confidence')];
            }
        }
        $this->points = $points;
        $this->image = (string) ($raw['image'] ?? '');
        $this->width = self::required($raw, 'width');
        $this->height = self::required($raw, 'height');
    }
}
