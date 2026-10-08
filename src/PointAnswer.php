<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The answer to a `point` question: one spot in an image, from its top-left
 * corner, `x` to the right and `y` down, in the question's units (pixels of
 * the image as sent, or fractions of its width and height when normalized).
 *
 * `present` is the probability that what the instructions describe is in the
 * image at all; `confidence` the probability that the spot lies on it if it
 * is. `image`, `width` and `height` name the image looked in and its size in
 * pixels. Every number is the JSON number the API sent.
 */
final class PointAnswer extends Answer
{
    public readonly int|float $x;

    public readonly int|float $y;

    public readonly int|float $present;

    public readonly int|float $confidence;

    public readonly string $image;

    public readonly int|float $width;

    public readonly int|float $height;

    /**
     * Whether `$raw` has every field of a point answer with the right types.
     *
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function wellFormed(array $raw): bool
    {
        return self::numbers($raw, 'x', 'y', 'present', 'confidence', 'width', 'height') && is_string($raw['image'] ?? null);
    }

    /**
     * @param array<array-key, mixed> $raw a well-formed point answer
     */
    public function __construct(array $raw)
    {
        parent::__construct('point', $raw);
        $this->x = self::required($raw, 'x');
        $this->y = self::required($raw, 'y');
        $this->present = self::required($raw, 'present');
        $this->confidence = self::required($raw, 'confidence');
        $this->image = (string) ($raw['image'] ?? '');
        $this->width = self::required($raw, 'width');
        $this->height = self::required($raw, 'height');
    }
}
