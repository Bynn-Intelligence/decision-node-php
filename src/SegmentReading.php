<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * Segment Vision's (`bynn_segment`) reading of one picture: the picture's
 * `width` and `height` in pixels, the `prompts` as read, and `instances`, the
 * matches by score (empty when none was found), each as sent: `label` (the
 * phrase it matched), `score`, `box` `[x1, y1, x2, y2]`, `polygon` (its
 * outline, `[[x, y], ...]`), `area` (its share of the picture), `centroid`
 * `[x, y]`, `opening` (null, or the largest hole it encloses: `polygon`,
 * `centroid`, `width`, `height`, `offset` `[dx, dy]` from the box's centre,
 * `area`), `depth` (null with depth off, or `median`, `opening_median` and
 * `opening_rim_median` in meters, each null when none) and `size_m` (null
 * with depth off, or `width`, `height` and `opening`, null or `{width,
 * height}`, in meters). `depthSource` is `bynn_depth` when the distances were
 * read, null otherwise, and `assumedHfovDeg` the field of view the sizes
 * assume. Coordinates are fractions of the picture from its top left;
 * distances and sizes are estimates from one picture, never a safety stop.
 */
final class SegmentReading extends Reading
{
    public readonly int $width;

    public readonly int $height;

    /** @var list<string> */
    public readonly array $prompts;

    /** @var list<array<string, mixed>> */
    public readonly array $instances;

    public readonly ?string $depthSource;

    public readonly int|float|null $assumedHfovDeg;

    /**
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function wellFormed(array $raw): bool
    {
        if (!is_string($raw['image'] ?? null) || !is_int($raw['width'] ?? null) || !is_int($raw['height'] ?? null)
            || (($raw['depth_source'] ?? null) !== null && !is_string($raw['depth_source']))
            || (($raw['assumed_hfov_deg'] ?? null) !== null && !self::isNumber($raw['assumed_hfov_deg']))) {
            return false;
        }
        $prompts = $raw['prompts'] ?? null;
        $instances = $raw['instances'] ?? null;
        if (!is_array($prompts) || !array_is_list($prompts) || !is_array($instances) || !array_is_list($instances)) {
            return false;
        }
        foreach ($prompts as $p) {
            if (!is_string($p)) {
                return false;
            }
        }
        foreach ($instances as $instance) {
            if (!self::instance($instance)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether one match is whole: a label, numeric score and area, a box of
     * four numbers, an outline and a centre of points, and an opening, a
     * depth and a size each null or whole.
     */
    private static function instance(mixed $m): bool
    {
        if (!is_array($m) || !is_string($m['label'] ?? null) || !self::isNumber($m['score'] ?? null) || !self::isNumber($m['area'] ?? null)
            || !self::numbers($m['box'] ?? null, 4) || !self::points($m['polygon'] ?? null) || !self::numbers($m['centroid'] ?? null, 2)) {
            return false;
        }
        $opening = $m['opening'] ?? null;
        if ($opening !== null && (!is_array($opening) || !self::points($opening['polygon'] ?? null) || !self::numbers($opening['centroid'] ?? null, 2)
            || !self::isNumber($opening['width'] ?? null) || !self::isNumber($opening['height'] ?? null)
            || !self::numbers($opening['offset'] ?? null, 2) || !self::isNumber($opening['area'] ?? null))) {
            return false;
        }
        $depth = $m['depth'] ?? null;
        if ($depth !== null && (!is_array($depth) || !self::nullableNumbers($depth, ['median', 'opening_median', 'opening_rim_median']))) {
            return false;
        }
        $size = $m['size_m'] ?? null;
        if ($size !== null) {
            if (!is_array($size) || !self::nullableNumbers($size, ['width', 'height'])) {
                return false;
            }
            $sizeOpening = $size['opening'] ?? null;
            if ($sizeOpening !== null && (!is_array($sizeOpening) || !self::nullableNumbers($sizeOpening, ['width', 'height']))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether each key is there, a number or null.
     *
     * @param array<array-key, mixed> $m
     * @param list<string>            $keys
     */
    private static function nullableNumbers(array $m, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $m) || ($m[$key] !== null && !self::isNumber($m[$key]))) {
                return false;
            }
        }

        return true;
    }

    /** Whether `$v` is a list of exactly `$n` numbers. */
    private static function numbers(mixed $v, int $n): bool
    {
        if (!is_array($v) || !array_is_list($v) || count($v) !== $n) {
            return false;
        }
        foreach ($v as $x) {
            if (!self::isNumber($x)) {
                return false;
            }
        }

        return true;
    }

    /** Whether `$v` is a list of points, each `[x, y]`. */
    private static function points(mixed $v): bool
    {
        if (!is_array($v) || !array_is_list($v)) {
            return false;
        }
        foreach ($v as $p) {
            if (!self::numbers($p, 2)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $raw a well-formed bynn_segment reading
     */
    public function __construct(array $raw)
    {
        parent::__construct((string) ($raw['image'] ?? ''), $raw);
        $this->width = is_int($raw['width'] ?? null) ? $raw['width'] : 0;
        $this->height = is_int($raw['height'] ?? null) ? $raw['height'] : 0;
        $prompts = [];
        foreach (is_array($raw['prompts'] ?? null) ? $raw['prompts'] : [] as $p) {
            if (is_string($p)) {
                $prompts[] = $p;
            }
        }
        $this->prompts = $prompts;
        $instances = [];
        foreach (is_array($raw['instances'] ?? null) ? $raw['instances'] : [] as $instance) {
            if (is_array($instance)) {
                /** @var array<string, mixed> $instance */
                $instances[] = $instance;
            }
        }
        $this->instances = $instances;
        $this->depthSource = is_string($raw['depth_source'] ?? null) ? $raw['depth_source'] : null;
        $hfov = $raw['assumed_hfov_deg'] ?? null;
        $this->assumedHfovDeg = is_int($hfov) || is_float($hfov) ? $hfov : null;
    }
}
