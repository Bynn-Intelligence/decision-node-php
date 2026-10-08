<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\Image;

/**
 * Every fixed rule the API documents, checked before anything is sent, so the
 * server never sees a request the client could have refused. The checks run
 * in the server's order (the nesting limit, every 422 entry, the first 400 in
 * question order, then the images) and a fault raises what the server would
 * answer: the same class, the same error type, the documented message, and
 * for a 422 the server's entries. A local error has no status and no request
 * id. A rule whose value only the models list states is checked against the
 * value the client holds, and skipped when it holds none. Token counts and
 * upload references stay the server's.
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
    public const MAX_NESTING = 128;
    public const MAX_WINDOW = 64;
    public const MAX_TTL_SECONDS = 3600;
    public const MAX_SEQ = 9_007_199_254_740_991;
    public const MAX_LIST_LIMIT = 100;
    public const MAX_CUSTOM_ID_CHARS = 512;

    private const TWO_53 = 9_007_199_254_740_992;
    private const MAX_NUMBER_DECIMALS = 6;
    private const DECIDE_TYPES = ['truth', 'noul', 'choice', 'score', 'number'];
    private const SYSTEMONE_TYPES = ['noul', 'choice', 'score'];
    private const MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Blocked address ranges for image URLs given as a literal address. */
    private const BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8', '2001:db8::/32', 'fd00:ec2::254/128',
    ];

    /**
     * A decision request (`/v1/decide`, `/v1/systemone`, a batch line): the
     * body's rules, then its images.
     *
     * @param array<string, mixed> $questions wire forms by id
     * @param list<mixed>          $images    Image objects or wire maps
     * @param array<string, mixed> $limits    the models list entry the client holds for the model, or []
     *
     * @throws BadRequestError|ValidationError
     */
    public static function decision(string $model, mixed $state, array $questions, array $images, array $limits, string $profile = 'decide'): void
    {
        $probe = ['model' => $model, 'state' => $state ?? '', 'questions' => (object) $questions];
        if ($images !== []) {
            // Placeholders, so the nesting counts the images without their bytes.
            $probe['images'] = array_fill(0, count($images), ['id' => '']);
        }
        self::nesting($probe);
        $entries = [];
        if ($state !== null && !self::sdl($state)) {
            array_push($entries, ...self::sdlEntries(['body', 'state'], self::json($state)));
        }
        self::questions($questions, $profile, $limits, $entries);
        self::images($images, $limits);
    }

    /**
     * `POST /v1/sessions`: window, ttl_seconds, instructions, state and every
     * question rule; a model the client knows serves no sessions.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $limits
     *
     * @throws BadRequestError|ValidationError
     */
    public static function sessionOpen(array $body, array $limits): void
    {
        self::nesting($body);
        $sessions = is_array($limits['sessions'] ?? null) ? $limits['sessions'] : [];
        $entries = [];
        foreach (['instructions', 'state'] as $name) {
            $value = $body[$name] ?? null;
            if ($value !== null && !self::sdl($value)) {
                array_push($entries, ...self::sdlEntries(['body', $name], self::json($value)));
            }
        }
        $bounds = [
            'window' => self::positive($sessions['max_window'] ?? null) ?? self::MAX_WINDOW,
            'ttl_seconds' => self::positive($sessions['max_ttl_seconds'] ?? null) ?? self::MAX_TTL_SECONDS,
        ];
        foreach ($bounds as $name => $most) {
            $value = $body[$name] ?? null;
            if ($value === null) {
                continue;
            }
            if (!is_int($value)) {
                $entries[] = self::entry('int_type', ['body', $name], 'Input should be a valid integer', $value);
            } elseif ($value < 1) {
                $entries[] = self::entry('greater_than_equal', ['body', $name], 'Input should be greater than or equal to 1', $value, ['ge' => 1]);
            } elseif ($value > $most) {
                $entries[] = self::entry('less_than_equal', ['body', $name], sprintf('Input should be less than or equal to %d', $most), $value, ['le' => $most]);
            }
        }
        $questions = $body['questions'] ?? [];
        self::questions(is_object($questions) ? get_object_vars($questions) : (array) $questions, 'decide', $limits, $entries);
        if (($sessions['enabled'] ?? null) === false) {
            throw self::bad(sprintf('Model %s does not serve sessions.', is_string($limits['model'] ?? null) ? $limits['model'] : ''));
        }
    }

    /**
     * A session frame, refused before anything goes on the socket.
     *
     * @param list<string>|null $known the question ids of the open response
     *
     * @throws BadRequestError
     */
    public static function frame(mixed $frame, mixed $image, mixed $questions, mixed $reset, mixed $seq, ?array $known): void
    {
        if ($seq !== null && (!is_int($seq) || $seq < 0 || $seq > self::MAX_SEQ)) {
            throw self::bad(sprintf('seq: must be an integer from 0 to %d.', self::MAX_SEQ));
        }
        if ($frame === null && $image === null) {
            throw self::bad('frame: a frame needs a frame value or an image.');
        }
        if ($frame !== null && (!self::sdl($frame) || $frame === '')) {
            throw self::bad('frame: must be a string, an object or an array, not empty.');
        }
        if ($questions !== null) {
            if (!is_array($questions) || $questions === []) {
                throw self::bad("questions: must be a non-empty list of the session's question ids.");
            }
            if ($known !== null) {
                foreach ($questions as $id) {
                    if (!in_array($id, $known, true)) {
                        throw self::bad(sprintf('questions: unknown question id %s', is_string($id) ? "'" . $id . "'" : Json::encode($id)));
                    }
                }
            }
        }
        if ($reset !== null && !is_bool($reset)) {
            throw self::bad('reset: must be true or false.');
        }
        if ($frame !== null && self::depth($frame) + 1 > self::MAX_NESTING) {
            throw self::bad(sprintf('The frame nests more than %d objects and arrays inside each other.', self::MAX_NESTING));
        }
    }

    /**
     * The custom_id rules of the requests of one batch call, before any line
     * is built. Lines are numbered from 1 within the call.
     *
     * @param list<array<array-key, mixed>> $requests
     * @param array<string, mixed>          $limits
     *
     * @throws BadRequestError
     */
    public static function batchRequests(array $requests, bool $allowEmpty, array $limits): void
    {
        if ($requests === [] && !$allowEmpty) {
            throw self::bad('The call holds no requests.');
        }
        $batch = is_array($limits['batch'] ?? null) ? $limits['batch'] : [];
        $most = self::positive($batch['requests_per_batch'] ?? null);
        if ($most !== null && count($requests) > $most) {
            throw self::bad(sprintf('The batch would hold %d requests. The limit is %d.', count($requests), $most));
        }
        $longest = self::positive($batch['max_custom_id_chars'] ?? null);
        $seen = [];
        foreach ($requests as $i => $request) {
            $n = $i + 1;
            $id = $request['custom_id'] ?? null;
            if ($id === null) {
                throw self::bad(sprintf('Line %d has no custom_id.', $n));
            }
            if (!is_string($id) || $id === '' || ($longest !== null && mb_strlen($id, 'UTF-8') > $longest)) {
                throw self::bad(sprintf('Line %d: custom_id must be a string of 1 to %d characters.', $n, $longest ?? self::MAX_CUSTOM_ID_CHARS));
            }
            if (isset($seen[$id])) {
                $shown = Json::encode($id);
                throw self::bad(sprintf('Line %d: custom_id %s is already in the batch.', $n, strlen($shown) <= 64 ? $shown : substr($shown, 0, 64) . '...'));
            }
            $seen[$id] = true;
        }
    }

    /**
     * `GET /v1/batches` paging: offset 0 or more, limit 1 to 100.
     *
     * @throws BadRequestError
     */
    public static function listQuery(?int $offset, ?int $limit): void
    {
        if ($offset !== null && $offset < 0) {
            throw self::bad(sprintf('Query parameter offset must be an integer of 0 or more, got %d.', $offset));
        }
        if ($limit !== null && ($limit < 1 || $limit > self::MAX_LIST_LIMIT)) {
            throw self::bad(sprintf('Query parameter limit must be an integer from 1 to %d, got %d.', self::MAX_LIST_LIMIT, $limit));
        }
    }

    /**
     * The image rules: how many, ids, and each image's own rules.
     *
     * @param list<mixed>          $images Image objects or wire maps
     * @param array<string, mixed> $limits
     *
     * @throws BadRequestError
     */
    public static function images(array $images, array $limits = []): void
    {
        $most = self::positive($limits['max_images'] ?? null) ?? self::MAX_IMAGES;
        if (count($images) > $most) {
            throw self::bad(sprintf('Too many images. Must have at most %d images.', $most));
        }
        $seen = [];
        foreach ($images as $i => $image) {
            $id = $image instanceof Image ? $image->id : self::field($image, 'id');
            if (!is_string($id) || $id === '') {
                throw self::bad(sprintf('Image %d needs an id.', $i));
            }
            if (isset($seen[$id])) {
                throw self::bad(sprintf('Duplicate image id: %s', $id));
            }
            $seen[$id] = true;
            if ($image instanceof Image) {
                self::image($image, $limits);
            }
        }
    }

    /**
     * One image's own rules. A frame's image is checked with `$frame`, which
     * also refuses a URL.
     *
     * @param array<string, mixed> $limits
     *
     * @throws BadRequestError
     */
    public static function image(Image $image, array $limits = [], bool $frame = false): void
    {
        if ($image->url !== null) {
            if ($frame) {
                throw self::bad('image: a frame takes bytes or an upload, not a url.');
            }
            self::url($image->id, $image->url);

            return;
        }
        if ($image->uploadId !== null) {
            if ($image->declaredMediaType !== null && !in_array($image->declaredMediaType, self::MEDIA_TYPES, true)) {
                throw self::bad(sprintf('Image %s: media_type must be image/jpeg, image/png or image/webp.', $image->id));
            }

            return;
        }
        if ($image->data === null) {
            return;
        }
        $data = $image->data;
        $found = ImageHeader::mediaType($data);
        if ($found === null) {
            throw self::bad(sprintf('Image %s is not a JPEG, PNG or WebP image.', $image->id));
        }
        if ($image->declaredMediaType !== null && $image->declaredMediaType !== $found) {
            throw self::bad(sprintf('Image %s could not be decoded as %s.', $image->id, $image->declaredMediaType));
        }
        $biggest = self::positive($limits['max_image_bytes'] ?? null) ?? self::MAX_IMAGE_BYTES;
        if (strlen($data) > $biggest) {
            throw self::bad(sprintf('Image %s is larger than 10 MB.', $image->id));
        }
        $size = ImageHeader::dimensions($data, $found);
        if ($size === null) {
            throw self::bad(sprintf('Image %s: its header cannot be read.', $image->id));
        }
        if ($size[0] * $size[1] > self::MAX_IMAGE_PIXELS) {
            throw self::bad(sprintf('Image %s is %dx%d pixels, over 40 megapixels.', $image->id, $size[0], $size[1]));
        }
    }

    /**
     * The URL rules that need no network: https, then at most 2,048
     * characters, no user information, port 443, a host, and no literal
     * address in a blocked range. A DNS name is the server's to resolve.
     *
     * @throws BadRequestError
     */
    public static function url(string $id, string $url): void
    {
        if (!str_starts_with($url, 'https://')) {
            throw self::bad(sprintf('Image %s: only https URLs are accepted.', $id));
        }
        $reason = null;
        $netloc = preg_match('~^https://([^/?#]*)~', $url, $m) === 1 ? $m[1] : '';
        if (strlen($url) > self::MAX_URL_LENGTH) {
            $reason = sprintf('the URL is %d characters, over the limit of %d', strlen($url), self::MAX_URL_LENGTH);
        } elseif (str_contains($netloc, '@')) {
            $reason = 'the URL carries user information';
        } else {
            [$host, $port] = self::hostAndPort($netloc);
            if ($port !== null && $port !== 443) {
                $reason = 'the URL uses a port other than 443';
            } elseif ($host === '') {
                $reason = 'the URL has no host';
            } elseif (self::blocked($host)) {
                $reason = 'the URL resolves to a private address';
            }
        }
        if ($reason !== null) {
            $message = sprintf("Image '%s': %s.", $id, $reason);

            throw new BadRequestError($message, errorType: 'image_url_refused', reason: $message);
        }
    }

    /**
     * floor((max - min) / step) + 1, in exact decimals when the numbers allow.
     */
    public static function gridSize(int|float $min, int|float $max, int|float $step): int|float
    {
        $parts = [self::decimal($min), self::decimal($max), self::decimal($step)];
        if ($parts[0] !== null && $parts[1] !== null && $parts[2] !== null) {
            $scale = max(0, -$parts[0][1], -$parts[1][1], -$parts[2][1]);
            $scaled = [];
            foreach ($parts as [$digits, $exponent]) {
                $negative = str_starts_with($digits, '-');
                $text = ltrim(($negative ? substr($digits, 1) : $digits) . str_repeat('0', $exponent + $scale), '0');
                if (strlen($text) > 18) {
                    $scaled = null;
                    break;
                }
                $scaled[] = ($negative ? -1 : 1) * (int) ($text === '' ? '0' : $text);
            }
            if ($scaled !== null && $scaled[2] > 0 && $scaled[1] >= $scaled[0]) {
                return intdiv($scaled[1] - $scaled[0], $scaled[2]) + 1;
            }
        }

        return floor(($max - $min) / $step) + 1;
    }

    /**
     * How many objects and arrays sit inside each other, the outermost included.
     */
    public static function depth(mixed $value): int
    {
        $deepest = 0;
        $stack = [[$value, 1]];
        while ($stack !== []) {
            [$node, $depth] = array_pop($stack);
            if ($node instanceof \JsonSerializable) {
                $node = $node->jsonSerialize();
            }
            if (is_object($node)) {
                $node = get_object_vars($node);
            }
            if (!is_array($node)) {
                continue;
            }
            $deepest = max($deepest, $depth);
            if ($deepest > self::MAX_NESTING + 1) {
                return $deepest;
            }
            foreach ($node as $child) {
                if (is_array($child) || is_object($child)) {
                    $stack[] = [$child, $depth + 1];
                }
            }
        }

        return $deepest;
    }

    // ---------------------------------------------------------------- questions

    /**
     * @param array<array-key, mixed>    $questions
     * @param array<string, mixed>       $limits
     * @param list<array<string, mixed>> $entries   422 entries found before the questions
     *
     * @throws BadRequestError|ValidationError
     */
    private static function questions(array $questions, string $profile, array $limits, array $entries): void
    {
        $deferred = null;
        foreach ($questions as $id => $question) {
            $found = self::question((string) $id, self::json($question), $profile, $limits, $entries);
            $deferred ??= $found;
        }
        if ($entries !== []) {
            throw new ValidationError('', entries: $entries, reason: 'the request does not have the shape the API expects');
        }
        if ($deferred !== null) {
            throw $deferred;
        }
    }

    /**
     * Adds the question's 422 entries and returns its first 400, if any.
     *
     * @param array<string, mixed>       $limits
     * @param list<array<string, mixed>> $entries
     */
    private static function question(string $id, mixed $q, string $profile, array $limits, array &$entries): ?BadRequestError
    {
        $base = ['body', 'questions', $id];
        if (!$q instanceof \stdClass) {
            $entries[] = self::entry('model_type', $base, 'Input should be a valid dictionary or object', $q);

            return null;
        }
        $type = $q->type ?? null;
        if ($type === null) {
            $entries[] = self::entry('missing', [...$base, 'type'], 'Field required', $q);

            return null;
        }
        if (!is_string($type) || !in_array($type, $profile === 'systemone' ? self::SYSTEMONE_TYPES : self::DECIDE_TYPES, true)) {
            return new BadRequestError('Invalid request.', errorType: 'api_usage_error', reason: sprintf("question '%s': unknown type %s", $id, Json::encode($type)));
        }
        $loc = [...$base, $type];
        $instructions = $q->instructions ?? null;
        if ($instructions !== null && !self::sdl($instructions)) {
            array_push($entries, ...self::sdlEntries([...$loc, 'instructions'], $instructions));
        }
        $criteria = $q->criteria ?? null;

        if ($type === 'number') {
            if (($limits['number_max_grid_values'] ?? null) === 0) {
                return self::bad(sprintf('Model %s does not answer number questions.', is_string($limits['model'] ?? null) ? $limits['model'] : ''));
            }

            return self::number($id, $q, $loc, $entries, self::positive($limits['number_max_grid_values'] ?? null) ?? self::MAX_GRID_VALUES);
        }
        if ($type === 'choice') {
            return self::choice($id, $q, $criteria, $loc, $entries, self::positive($limits['max_choice_options'] ?? null) ?? self::MAX_CHOICE_OPTIONS);
        }
        if ($type === 'score') {
            return self::score($q, $criteria, $loc, $entries, $profile, self::positive($limits['max_score_levels'] ?? null) ?? self::MAX_SCORE_LEVELS);
        }

        // truth, and the wire name /v1/systemone takes for it
        if ($criteria !== null && !$criteria instanceof \stdClass) {
            $entries[] = self::entry('dict_type', [...$loc, 'criteria'], 'Input should be a valid dictionary', $criteria);

            return null;
        }
        $sides = $criteria instanceof \stdClass ? get_object_vars($criteria) : [];
        foreach (['true', 'false'] as $side) {
            if (($sides[$side] ?? null) !== null && !self::sdl($sides[$side])) {
                array_push($entries, ...self::sdlEntries([...$loc, 'criteria', $side], $sides[$side]));
            }
        }
        if ($instructions === null && ($sides['true'] ?? null) === null && ($sides['false'] ?? null) === null) {
            return self::plain(sprintf('%s question must have criteria or instructions: %s', $type === 'noul' ? 'Noul' : 'Truth', $id));
        }

        return null;
    }

    /**
     * @param list<string|int>           $loc
     * @param list<array<string, mixed>> $entries
     */
    private static function choice(string $id, \stdClass $q, mixed $criteria, array $loc, array &$entries, int $most): ?BadRequestError
    {
        if ($criteria === null) {
            $entries[] = self::entry('missing', [...$loc, 'criteria'], 'Field required', $q);

            return null;
        }
        if (!$criteria instanceof \stdClass) {
            $entries[] = self::entry('dict_type', [...$loc, 'criteria'], 'Input should be a valid dictionary', $criteria);

            return null;
        }
        $options = get_object_vars($criteria);
        foreach ($options as $name => $description) {
            if ($description !== null && !self::sdl($description)) {
                array_push($entries, ...self::sdlEntries([...$loc, 'criteria', (string) $name], $description));
            }
        }
        if ($options === []) {
            return self::plain(sprintf('Choice question must have at least one choice: %s', $id));
        }
        if (count($options) > $most) {
            return self::plain(sprintf('Too many choices. Must have at most %d choices.', $most));
        }

        return null;
    }

    /**
     * Levels are a list; an object keyed "0" to "K-1" is a list on /v1/decide only.
     *
     * @param list<string|int>           $loc
     * @param list<array<string, mixed>> $entries
     */
    private static function score(\stdClass $q, mixed $criteria, array $loc, array &$entries, string $profile, int $most): ?BadRequestError
    {
        if ($criteria === null) {
            $entries[] = self::entry('missing', [...$loc, 'criteria'], 'Field required', $q);

            return null;
        }
        $levels = null;
        if (is_array($criteria)) {
            $levels = $criteria;
        } elseif ($criteria instanceof \stdClass && $profile !== 'systemone') {
            $byIndex = get_object_vars($criteria);
            $keys = array_map('strval', array_keys($byIndex));
            if (self::indexKeys($keys)) {
                $levels = [];
                foreach (range(0, count($keys) - 1) as $i) {
                    $levels[] = $byIndex[$i] ?? null;
                }
            }
        }
        if ($levels === null) {
            $entries[] = self::entry('list_type', [...$loc, 'criteria'], 'Input should be a valid list', $criteria);

            return null;
        }
        if ($levels === []) {
            $entries[] = self::entry('too_short', [...$loc, 'criteria'], 'List should have at least 1 item after validation, not 0', $criteria);

            return null;
        }
        foreach ($levels as $i => $level) {
            if (!self::sdl($level)) {
                array_push($entries, ...self::sdlEntries([...$loc, 'criteria', $i], $level));
            }
        }

        return count($levels) > $most ? self::plain(sprintf('Too many score levels. Must have at most %d levels.', $most)) : null;
    }

    /**
     * The number grid: the 422 entries in the server's order, then the 400
     * for missing instructions.
     *
     * @param list<string|int>           $loc
     * @param list<array<string, mixed>> $entries
     */
    private static function number(string $id, \stdClass $q, array $loc, array &$entries, int $maxValues): ?BadRequestError
    {
        $defaults = ['min' => 0, 'max' => 255, 'step' => 1];
        $sent = [];
        $values = [];
        $ok = true;
        foreach ($defaults as $name => $default) {
            $value = $q->{$name} ?? null;
            $sent[$name] = $value;
            $fieldLoc = [...$loc, $name];
            if ($value === null) {
                $values[$name] = $default;
                continue;
            }
            if (!is_int($value) && !is_float($value)) {
                $entries[] = self::entry('float_type', $fieldLoc, 'Input should be a valid number', $value);
                $ok = false;
                continue;
            }
            if (is_float($value) && !is_finite($value)) {
                $entries[] = self::entry('finite_number', $fieldLoc, 'Input should be a finite number', $value);
                $ok = false;
                continue;
            }
            if ($name !== 'step' && $value < -self::TWO_53) {
                $entries[] = self::entry('greater_than_equal', $fieldLoc, sprintf('Input should be greater than or equal to -%d', self::TWO_53), $value, ['ge' => -self::TWO_53]);
                $ok = false;
                continue;
            }
            if ($name !== 'step' && $value > self::TWO_53) {
                $entries[] = self::entry('less_than_equal', $fieldLoc, sprintf('Input should be less than or equal to %d', self::TWO_53), $value, ['le' => self::TWO_53]);
                $ok = false;
                continue;
            }
            if ($name === 'step' && $value <= 0) {
                $entries[] = self::entry('greater_than', $fieldLoc, 'Input should be greater than 0', $value, ['gt' => 0]);
                $ok = false;
                continue;
            }
            if ($name !== 'max' && self::places($value) > self::MAX_NUMBER_DECIMALS) {
                $entries[] = self::entry('decimal_max_places', $fieldLoc, sprintf('Decimal input should have no more than %d decimal places', self::MAX_NUMBER_DECIMALS), $value, ['decimal_places' => self::MAX_NUMBER_DECIMALS]);
                $ok = false;
                continue;
            }
            $values[$name] = $value;
        }
        if (!$ok) {
            return null;
        }
        $lo = $values['min'] ?? 0;
        $hi = $values['max'] ?? 255;
        $step = $values['step'] ?? 1;
        $d = max(self::places($lo), self::places($step));
        if ($d > 0) {
            // 10^(15-d) - 10^-d: 15-d nines, the point, d nines.
            $text = str_repeat('9', 15 - $d) . '.' . str_repeat('9', $d);
            $bound = (float) $text;
            foreach (['min' => $lo, 'max' => $hi] as $name => $value) {
                if ($value > $bound) {
                    $entries[] = self::entry('less_than_equal', [...$loc, $name], 'Input should be less than or equal to ' . $text, $sent[$name], ['le' => $bound]);

                    return null;
                }
                if ($value < -$bound) {
                    $entries[] = self::entry('greater_than_equal', [...$loc, $name], 'Input should be greater than or equal to -' . $text, $sent[$name], ['ge' => -$bound]);

                    return null;
                }
            }
        }
        if ($lo > $hi) {
            $maxSent = $sent['max'] ?? 255;
            $entries[] = self::entry('less_than_equal', [...$loc, 'min'], 'Input should be less than or equal to ' . Json::encode($maxSent), $sent['min'] ?? 0, ['le' => $maxSent]);

            return null;
        }
        $count = self::gridSize($lo, $hi, $step);
        if ($count > $maxValues) {
            $shown = is_float($count) ? sprintf('%.0f', $count) : (string) $count;
            $entries[] = self::entry('too_long', $loc, sprintf('Grid should have at most %d values, not %s', $maxValues, $shown), $q, [
                'field_type' => 'Grid',
                'max_length' => $maxValues,
                'actual_length' => is_float($count) ? (int) $count : $count,
            ]);

            return null;
        }
        $instructions = $q->instructions ?? null;
        if ($instructions === null || $instructions === '') {
            return self::plain(sprintf('Number question must have instructions: %s', $id));
        }

        return null;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @throws BadRequestError
     */
    private static function nesting(mixed $body): void
    {
        $depth = self::depth($body);
        if ($depth > self::MAX_NESTING) {
            throw new BadRequestError('Invalid request.', errorType: 'api_usage_error', reason: sprintf(
                'the request nests %d objects and arrays inside each other; the limit is %d',
                $depth,
                self::MAX_NESTING,
            ));
        }
    }

    /**
     * The value as the server reads it: JSON objects as \stdClass, arrays as lists.
     */
    private static function json(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return json_decode(Json::encode($value), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A string, an object or an array: what instructions, a state, a
     * description and a level may be.
     */
    private static function sdl(mixed $value): bool
    {
        return is_string($value) || is_array($value) || is_object($value);
    }

    /**
     * @param list<string|int> $loc
     *
     * @return list<array<string, mixed>>
     */
    private static function sdlEntries(array $loc, mixed $value): array
    {
        return [
            self::entry('string_type', [...$loc, 'str'], 'Input should be a valid string', $value),
            self::entry('dict_type', [...$loc, 'dict[any,any]'], 'Input should be a valid dictionary', $value),
            self::entry('list_type', [...$loc, 'list[any]'], 'Input should be a valid list', $value),
        ];
    }

    /**
     * @param list<string|int>          $loc
     * @param array<string, mixed>|null $ctx
     *
     * @return array<string, mixed>
     */
    private static function entry(string $type, array $loc, string $msg, mixed $input, ?array $ctx = null): array
    {
        $entry = ['type' => $type, 'loc' => $loc, 'msg' => $msg, 'input' => $input];
        if ($ctx !== null) {
            $entry['ctx'] = $ctx;
        }

        return $entry;
    }

    /**
     * Whether the keys are exactly "0" to "K-1".
     *
     * @param list<string> $keys
     */
    private static function indexKeys(array $keys): bool
    {
        if ($keys === []) {
            return false;
        }
        $sorted = $keys;
        usort($sorted, static fn (string $a, string $b): int => [strlen($a), $a] <=> [strlen($b), $b]);

        return $sorted === array_map('strval', range(0, count($keys) - 1));
    }

    /**
     * Decimal places of a number's shortest decimal form.
     */
    private static function places(int|float $value): int
    {
        $parts = self::decimal($value);

        return $parts === null ? 0 : max(0, -$parts[1]);
    }

    /**
     * A number's shortest decimal form as [signed digits, exponent], the
     * value being digits * 10^exponent, with trailing zeros taken off.
     *
     * @return array{string, int}|null
     */
    private static function decimal(int|float $value): ?array
    {
        $text = Json::encode($value);
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $text, $m) !== 1) {
            return null;
        }
        $fraction = $m[3] ?? '';
        $digits = ltrim($m[2] . $fraction, '0');
        $exponent = (int) ($m[4] ?? 0) - strlen($fraction);
        if ($digits === '') {
            return ['0', 0];
        }
        $trimmed = rtrim($digits, '0');
        $exponent += strlen($digits) - strlen($trimmed);

        return [$m[1] . $trimmed, $exponent];
    }

    /**
     * @return array{string, int|null} the host (lower case, without brackets) and the port, -1 for one that cannot be read
     */
    private static function hostAndPort(string $netloc): array
    {
        if (str_starts_with($netloc, '[')) {
            $end = strpos($netloc, ']');
            if ($end === false) {
                return ['', null];
            }
            $host = substr($netloc, 1, $end - 1);
            $rest = substr($netloc, $end + 1);
        } else {
            $colon = strrpos($netloc, ':');
            $host = $colon === false ? $netloc : substr($netloc, 0, $colon);
            $rest = $colon === false ? '' : substr($netloc, $colon);
        }
        $port = null;
        if ($rest !== '' && $rest !== ':') {
            $digits = substr($rest, 1);
            $port = ($rest[0] === ':' && ctype_digit($digits) && (int) $digits <= 65535) ? (int) $digits : -1;
        }

        return [strtolower($host), $port];
    }

    private static function blocked(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $packed = @inet_pton($host);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16 && (
            str_starts_with($packed, str_repeat("\0", 10) . "\xFF\xFF")
            || str_starts_with($packed, "\x00\x64\xFF\x9B" . str_repeat("\0", 8))
        )) {
            // IPv4-mapped and NAT64 addresses are judged by the IPv4 address they embed.
            $packed = substr($packed, 12);
        }
        foreach (self::BLOCKED as $cidr) {
            [$network, $bits] = explode('/', $cidr);
            $net = (string) inet_pton($network);
            if (strlen($net) !== strlen($packed)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    private static function field(mixed $container, string $name): mixed
    {
        if (is_array($container)) {
            return $container[$name] ?? null;
        }
        if (is_object($container)) {
            return $container->{$name} ?? null;
        }

        return null;
    }

    private static function positive(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * A refusal the API answers with `api_usage_error`.
     */
    private static function bad(string $message): BadRequestError
    {
        return new BadRequestError($message, errorType: 'api_usage_error', reason: $message);
    }

    /**
     * A refusal the API answers with a plain-string detail: no error type.
     */
    private static function plain(string $message): BadRequestError
    {
        return new BadRequestError($message, reason: $message);
    }
}
