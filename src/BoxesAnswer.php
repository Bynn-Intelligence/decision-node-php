<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The answer to a `box` question asked with `max`: up to `max` rectangles in
 * an image. `count` is how many it found and `boxes` the rectangles, left to
 * right by their centre (ties from the top), each its top-left (`x1`, `y1`)
 * and bottom-right (`x2`, `y2`) corners and its `confidence`, in the
 * question's units.
 *
 * `present` is the probability that what the instructions describe is in the
 * image at all, and `complete` the probability that `boxes` holds every one
 * of them. `image`, `width` and `height` name the image looked in and its
 * size in pixels. Every number is the JSON number the API sent.
 */
final class BoxesAnswer extends Answer
{
    public readonly int $count;

    public readonly int|float $present;

    public readonly int|float $complete;

    /** @var list<array{x1: int|float, y1: int|float, x2: int|float, y2: int|float, confidence: int|float}> */
    public readonly array $boxes;

    public readonly string $image;

    public readonly int|float $width;

    public readonly int|float $height;

    /**
     * Whether `$raw` has every field of a several-boxes answer with the right
     * types.
     *
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function wellFormed(array $raw, ?\stdClass $shape = null): bool
    {
        return self::placesWellFormed($raw, $shape, 'boxes', 'x1', 'y1', 'x2', 'y2', 'confidence');
    }

    /**
     * @param array<array-key, mixed> $raw a well-formed several-boxes answer
     */
    public function __construct(array $raw)
    {
        parent::__construct('box', $raw);
        $this->count = is_int($raw['count'] ?? null) ? $raw['count'] : 0;
        $this->present = self::required($raw, 'present');
        $this->complete = self::required($raw, 'complete');
        $boxes = [];
        foreach (is_array($raw['boxes'] ?? null) ? $raw['boxes'] : [] as $box) {
            if (is_array($box)) {
                $boxes[] = [
                    'x1' => self::required($box, 'x1'),
                    'y1' => self::required($box, 'y1'),
                    'x2' => self::required($box, 'x2'),
                    'y2' => self::required($box, 'y2'),
                    'confidence' => self::required($box, 'confidence'),
                ];
            }
        }
        $this->boxes = $boxes;
        $this->image = (string) ($raw['image'] ?? '');
        $this->width = self::required($raw, 'width');
        $this->height = self::required($raw, 'height');
    }
}
