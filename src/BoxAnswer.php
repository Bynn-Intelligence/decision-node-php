<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The answer to a `box` question: a rectangle in an image, its top-left
 * corner (`x1`, `y1`) and bottom-right corner (`x2`, `y2`) from the image's
 * top-left corner, in the question's units (pixels of the image as sent, or
 * fractions of its width and height when normalized).
 *
 * `present` is the probability that what the instructions describe is in the
 * image at all; `confidence` the probability that the box is right if it is.
 * `image`, `width` and `height` name the image looked in and its size in
 * pixels. Every number is the JSON number the API sent.
 */
final class BoxAnswer extends Answer
{
    public readonly int|float $x1;

    public readonly int|float $y1;

    public readonly int|float $x2;

    public readonly int|float $y2;

    public readonly int|float $present;

    public readonly int|float $confidence;

    public readonly string $image;

    public readonly int|float $width;

    public readonly int|float $height;

    /**
     * Whether `$raw` has every field of a box answer with the right types.
     *
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function wellFormed(array $raw): bool
    {
        return self::numbers($raw, 'x1', 'y1', 'x2', 'y2', 'present', 'confidence', 'width', 'height') && is_string($raw['image'] ?? null);
    }

    /**
     * @param array<array-key, mixed> $raw a well-formed box answer
     */
    public function __construct(array $raw)
    {
        parent::__construct('box', $raw);
        $this->x1 = self::required($raw, 'x1');
        $this->y1 = self::required($raw, 'y1');
        $this->x2 = self::required($raw, 'x2');
        $this->y2 = self::required($raw, 'y2');
        $this->present = self::required($raw, 'present');
        $this->confidence = self::required($raw, 'confidence');
        $this->image = (string) ($raw['image'] ?? '');
        $this->width = self::required($raw, 'width');
        $this->height = self::required($raw, 'height');
    }
}
