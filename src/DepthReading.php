<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * Depth Vision's (`bynn_depth`) reading of one picture, in meters: `ahead`,
 * the nearest obstacle straight ahead (the middle third of the picture's
 * width), the floor left out, null when none is found; `directions`, the same
 * in 7 equal strips from left to right, each null when none (any other count
 * makes the reading an UnknownReading); `ground`, true
 * when a floor or ground was found and left out; `grid`, `gridRows` rows of
 * `gridCols` distances, row by row from the top left; `depthMin`, `depthMax`
 * and `depthMedian`; and the picture's `width` and `height` in pixels. The
 * distances are estimates from one picture, for decisions and planning,
 * never a safety stop.
 */
final class DepthReading extends Reading
{
    public readonly int|float|null $ahead;

    /** @var list<int|float|null> */
    public readonly array $directions;

    public readonly bool $ground;

    public readonly int $gridRows;

    public readonly int $gridCols;

    /** @var list<list<int|float>> */
    public readonly array $grid;

    public readonly int|float $depthMin;

    public readonly int|float $depthMax;

    public readonly int|float $depthMedian;

    public readonly int $width;

    public readonly int $height;

    /**
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function wellFormed(array $raw): bool
    {
        if (!is_string($raw['image'] ?? null) || (($raw['ahead'] ?? null) !== null && !self::isNumber($raw['ahead']))
            || !is_bool($raw['ground'] ?? null)) {
            return false;
        }
        foreach (['grid_rows', 'grid_cols', 'width', 'height'] as $key) {
            if (!is_int($raw[$key] ?? null) || $raw[$key] < 0) {
                return false;
            }
        }
        foreach (['depth_min', 'depth_max', 'depth_median'] as $key) {
            if (!self::isNumber($raw[$key] ?? null)) {
                return false;
            }
        }
        $directions = $raw['directions'] ?? null;
        if (!is_array($directions) || !array_is_list($directions) || count($directions) !== 7) {
            return false;
        }
        foreach ($directions as $d) {
            if ($d !== null && !self::isNumber($d)) {
                return false;
            }
        }
        $grid = $raw['grid'] ?? null;
        if (!is_array($grid) || !array_is_list($grid)) {
            return false;
        }
        foreach ($grid as $row) {
            if (!is_array($row) || !array_is_list($row)) {
                return false;
            }
            foreach ($row as $cell) {
                if (!self::isNumber($cell)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $raw a well-formed bynn_depth reading
     */
    public function __construct(array $raw)
    {
        parent::__construct((string) ($raw['image'] ?? ''), $raw);
        $number = static fn (mixed $v): int|float => is_int($v) || is_float($v) ? $v : 0;
        $this->ahead = is_int($raw['ahead'] ?? null) || is_float($raw['ahead'] ?? null) ? $raw['ahead'] : null;
        $directions = [];
        foreach (is_array($raw['directions'] ?? null) ? $raw['directions'] : [] as $d) {
            $directions[] = is_int($d) || is_float($d) ? $d : null;
        }
        $this->directions = $directions;
        $this->ground = ($raw['ground'] ?? null) === true;
        $this->gridRows = is_int($raw['grid_rows'] ?? null) ? $raw['grid_rows'] : 0;
        $this->gridCols = is_int($raw['grid_cols'] ?? null) ? $raw['grid_cols'] : 0;
        $grid = [];
        foreach (is_array($raw['grid'] ?? null) ? $raw['grid'] : [] as $row) {
            $cells = [];
            foreach (is_array($row) ? $row : [] as $cell) {
                $cells[] = $number($cell);
            }
            $grid[] = $cells;
        }
        $this->grid = $grid;
        $this->depthMin = $number($raw['depth_min'] ?? null);
        $this->depthMax = $number($raw['depth_max'] ?? null);
        $this->depthMedian = $number($raw['depth_median'] ?? null);
        $this->width = is_int($raw['width'] ?? null) ? $raw['width'] : 0;
        $this->height = is_int($raw['height'] ?? null) ? $raw['height'] : 0;
    }
}
