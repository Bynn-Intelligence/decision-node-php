<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\Image;

/**
 * The API's fixed request rules, checked before any network call so a request
 * the API would refuse costs no round trip. A fault raises the class the API
 * answers it with (BadRequestError, or ValidationError where the API answers
 * 422), with no status and no request id. Token limits stay the server's.
 *
 * @internal
 */
final class LocalChecks
{
    public const MAX_IMAGES = 4;
    public const MAX_IMAGE_BYTES = 10_485_760;
    public const MAX_IMAGE_PIXELS = 40_000_000;
    public const MAX_URL_LENGTH = 2048;
    public const MAX_CHOICE_OPTIONS = 255;
    public const MAX_SCORE_LEVELS = 10;
    public const MAX_GRID_VALUES = 4096;

    private const NUMBER_DEFAULTS = ['min' => 0, 'max' => 255, 'step' => 1];

    /**
     * @param array<array-key, mixed> $questions wire forms by question id
     *
     * @throws BadRequestError|ValidationError
     */
    public static function questions(array $questions): void
    {
        foreach ($questions as $id => $question) {
            self::question((string) $id, $question);
        }
    }

    /**
     * @param list<mixed> $images Image objects or wire maps
     *
     * @throws BadRequestError
     */
    public static function images(array $images, bool $frame = false): void
    {
        if (count($images) > self::MAX_IMAGES) {
            throw self::bad(sprintf('Too many images. Must have at most %d images.', self::MAX_IMAGES));
        }
        $ids = [];
        foreach ($images as $image) {
            if (!$image instanceof Image) {
                continue;
            }
            if (!$frame) {
                if (isset($ids[$image->id])) {
                    throw self::bad(sprintf("Image '%s': image ids must be unique within a request.", $image->id));
                }
                $ids[$image->id] = true;
            }
            self::image($image, $frame);
        }
    }

    /**
     * @throws BadRequestError
     */
    public static function image(Image $image, bool $frame = false): void
    {
        if ($image->url !== null) {
            if ($frame) {
                throw self::bad('image: a frame takes bytes or an upload id, not a url.');
            }
            $scheme = strtolower((string) parse_url($image->url, PHP_URL_SCHEME));
            $host = (string) parse_url($image->url, PHP_URL_HOST);
            if ($scheme !== 'https' || $host === '' || strlen($image->url) > self::MAX_URL_LENGTH) {
                throw self::bad(sprintf("Image '%s': only https URLs of at most %d characters are accepted.", $image->id, self::MAX_URL_LENGTH));
            }

            return;
        }
        if ($image->data === null) {
            return;
        }
        $data = $image->data;
        $found = ImageHeader::mediaType($data);
        if ($found === null) {
            throw self::bad(sprintf("Image '%s' is not a JPEG, PNG or WebP image.", $image->id));
        }
        if ($image->declaredMediaType !== null && $image->declaredMediaType !== $found) {
            throw self::bad(sprintf("Image '%s' could not be decoded as %s.", $image->id, $image->declaredMediaType));
        }
        if (strlen($data) > self::MAX_IMAGE_BYTES) {
            throw self::bad(sprintf("Image '%s' is larger than 10 MB.", $image->id));
        }
        $size = ImageHeader::dimensions($data, $found);
        if ($size === null) {
            throw self::bad(sprintf("Image '%s': its header cannot be read.", $image->id));
        }
        if ($size[0] * $size[1] > self::MAX_IMAGE_PIXELS) {
            throw self::bad(sprintf("Image '%s' is %dx%d pixels, over the limit of 40 megapixels.", $image->id, $size[0], $size[1]));
        }
    }

    private static function question(string $id, mixed $question): void
    {
        $type = self::field($question, 'type');
        $criteria = self::field($question, 'criteria');
        $instructions = self::field($question, 'instructions');

        if ($type === 'choice') {
            $count = self::mapCount($criteria);
            if ($count === 0) {
                throw self::bad(sprintf('Choice question must have at least one choice: %s', $id));
            }
            if ($count !== null && $count > self::MAX_CHOICE_OPTIONS) {
                throw self::bad(sprintf('Too many choices. Must have at most %d choices.', self::MAX_CHOICE_OPTIONS));
            }
        } elseif ($type === 'score') {
            $count = $criteria instanceof \stdClass ? count(get_object_vars($criteria)) : (is_array($criteria) ? count($criteria) : null);
            if ($count === 0) {
                throw self::invalid($id, ['body', 'questions', $id, 'score', 'criteria'], 'too_short', 'A score needs at least one level', sprintf("question '%s': a score needs at least one level", $id));
            }
            if ($count !== null && $count > self::MAX_SCORE_LEVELS) {
                throw self::bad(sprintf('Too many score levels. Must have at most %d levels.', self::MAX_SCORE_LEVELS));
            }
        } elseif ($type === 'truth' || $type === 'noul') {
            $true = self::field($criteria, 'true');
            $false = self::field($criteria, 'false');
            if ($instructions === null && $true === null && $false === null) {
                throw self::bad(sprintf('Truth question must have criteria or instructions: %s', $id));
            }
        } elseif ($type === 'number') {
            self::grid($id, $question);
            if ($instructions === null || $instructions === '') {
                throw self::bad(sprintf('Number question must have instructions: %s', $id));
            }
        }
    }

    /**
     * The number grid: step above 0, min at most max, at most 4,096
     * values, counted in exact decimals from the numbers as written.
     */
    private static function grid(string $id, mixed $question): void
    {
        $values = [];
        foreach (self::NUMBER_DEFAULTS as $name => $default) {
            $value = self::field($question, $name) ?? $default;
            if (!is_int($value) && !is_float($value)) {
                throw self::invalid($id, ['body', 'questions', $id, 'number', $name], 'float_type', 'Input should be a valid number', sprintf("question '%s': %s must be a number", $id, $name));
            }
            $values[$name] = $value;
        }
        if ($values['step'] <= 0) {
            throw self::invalid($id, ['body', 'questions', $id, 'number', 'step'], 'greater_than', 'Input should be greater than 0', sprintf("question '%s': step must be above 0", $id));
        }
        if ($values['min'] > $values['max']) {
            throw self::invalid(
                $id,
                ['body', 'questions', $id, 'number', 'min'],
                'less_than_equal',
                sprintf('Input should be less than or equal to %s', Json::encode($values['max'])),
                sprintf("question '%s': min %s is above max %s", $id, Json::encode($values['min']), Json::encode($values['max'])),
            );
        }
        $count = self::gridSize($values['min'], $values['max'], $values['step']);
        if ($count > self::MAX_GRID_VALUES) {
            throw self::invalid(
                $id,
                ['body', 'questions', $id, 'number'],
                'value_error',
                sprintf('The grid holds %s values; the limit is %d', is_float($count) ? sprintf('%.0f', $count) : (string) $count, self::MAX_GRID_VALUES),
                sprintf("question '%s': the grid holds %s values, the limit is %d", $id, is_float($count) ? sprintf('%.0f', $count) : (string) $count, self::MAX_GRID_VALUES),
            );
        }
    }

    /**
     * floor((max - min) / step) + 1, in exact decimals when the numbers allow.
     */
    public static function gridSize(int|float $min, int|float $max, int|float $step): int|float
    {
        $parts = [self::decimal($min), self::decimal($max), self::decimal($step)];
        if (!in_array(null, $parts, true)) {
            $scale = max($parts[0][1], $parts[1][1], $parts[2][1]);
            $scaled = [];
            foreach ($parts as [$digits, $decimals]) {
                $text = $digits . str_repeat('0', $scale - $decimals);
                $negative = str_starts_with($text, '-');
                $text = ltrim($negative ? substr($text, 1) : $text, '0');
                if (strlen($text) > 17) {
                    $scaled = null;
                    break;
                }
                $scaled[] = ($negative ? -1 : 1) * (int) ($text === '' ? '0' : $text);
            }
            if ($scaled !== null && $scaled[2] > 0) {
                return intdiv($scaled[1] - $scaled[0], $scaled[2]) + 1;
            }
        }

        return floor(($max - $min) / $step) + 1;
    }

    /**
     * A number's shortest decimal text as [digits without the point, number of decimals].
     *
     * @return array{string, int}|null
     */
    private static function decimal(int|float $value): ?array
    {
        $text = Json::encode($value);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $text, $m)) {
            return null;
        }
        $fraction = rtrim($m[3] ?? '', '0');

        return [$m[1] . $m[2] . $fraction, strlen($fraction)];
    }

    private static function mapCount(mixed $criteria): ?int
    {
        if ($criteria instanceof \stdClass) {
            return count(get_object_vars($criteria));
        }
        if (is_array($criteria)) {
            return $criteria === [] || !array_is_list($criteria) ? count($criteria) : null;
        }

        return null;
    }

    private static function field(mixed $container, string $name): mixed
    {
        if (is_array($container)) {
            return $container[$name] ?? null;
        }
        if ($container instanceof \JsonSerializable) {
            return self::field($container->jsonSerialize(), $name);
        }
        if (is_object($container)) {
            return $container->{$name} ?? null;
        }

        return null;
    }

    private static function bad(string $message): BadRequestError
    {
        return new BadRequestError($message, errorType: 'api_usage_error', reason: $message);
    }

    /**
     * @param list<string> $loc
     */
    private static function invalid(string $id, array $loc, string $type, string $msg, string $reason): ValidationError
    {
        return new ValidationError($msg, entries: [['type' => $type, 'loc' => $loc, 'msg' => $msg]], reason: $reason);
    }
}
