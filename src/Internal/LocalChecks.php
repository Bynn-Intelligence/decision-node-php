<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\RequestTooLargeError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\Image;
use Bynn\DecisionNode\Video;
use Bynn\DecisionNode\VideoFrame;

/**
 * Every fixed rule the API documents, checked before anything is sent, so the
 * server never sees a request the client could have refused. The checks run
 * in the server's order (the nesting limit, every 422 entry, the first 400 in
 * question order, then the images, then the videos) and a fault raises what the server would
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
    public const MAX_IMAGES = 16;
    /** The largest request body the API takes, 25 MiB; inline images and video frames count, uploads and URLs do not. */
    public const MAX_BODY_BYTES = 26_214_400;
    /** Files one `POST /v1/uploads` takes. */
    public const MAX_UPLOAD_FILES = 16;
    public const MAX_VIDEOS = 4;
    public const MAX_FPS = 4;
    public const MAX_IMAGE_BYTES = 10_485_760;
    public const MAX_IMAGE_PIXELS = 40_000_000;
    public const MAX_URL_LENGTH = 2048;
    /** The longest image id, in characters (code points). */
    public const MAX_IMAGE_ID_CHARS = 64;
    /** The longest name a collection may have, in characters. */
    public const MAX_COLLECTION_NAME_CHARS = 80;
    /** The longest reference an item of a collection may carry. */
    public const MAX_ITEM_REFERENCE_CHARS = 64;
    /** The longest label an item of a collection may carry. */
    public const MAX_ITEM_LABEL_CHARS = 128;
    /** The characters an item's reference is made of. */
    /** How much of a string a collections 422 entry echoes. */
    private const ECHO_CHARS = 64;

    /** The limits of an item's metadata. */
    public const MAX_METADATA_KEYS = 20;
    public const MAX_METADATA_KEY_CHARS = 40;
    public const MAX_METADATA_TEXT_CHARS = 500;

    public const ITEM_REFERENCE_PATTERN = '^[A-Za-z0-9_.:-]{1,64}$';
    public const MAX_CHOICE_OPTIONS = 255;
    public const MAX_SCORE_LEVELS = 10;
    public const MAX_GRID_VALUES = 4096;
    /** The fewest and the most places a point or box question's `max` asks for. */
    public const MIN_PLACES = 2;
    public const MAX_PLACES = 16;
    public const MAX_NESTING = 128;
    public const MAX_WINDOW = 64;
    public const MAX_TTL_SECONDS = 3600;
    public const MAX_SEQ = 9_007_199_254_740_991;
    public const MAX_LIST_LIMIT = 100;
    public const MAX_CUSTOM_ID_CHARS = 512;

    private const TWO_53 = 9_007_199_254_740_992;
    private const MAX_NUMBER_DECIMALS = 6;
    private const DECIDE_TYPES = ['truth', 'noul', 'choice', 'score', 'number', 'point', 'box'];
    private const UNITS = ['pixels', 'normalized'];
    private const MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Blocked address ranges for image URLs given as a literal address. */
    private const BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8', '2001:db8::/32', 'fd00:ec2::254/128',
    ];

    /**
     * A decision request (`/v1/decide`, a batch line): the
     * body's rules, then its images, then its videos.
     *
     * @param array<string, mixed> $questions wire forms by id
     * @param list<mixed>          $images    Image objects or wire maps
     * @param array<string, mixed> $limits    the models list entry the client holds for the model, or []
     * @param list<mixed>          $videos    Video objects or wire maps
     * @param list<mixed>          $boosters  names, Booster objects or wire forms; only their nesting is checked
     *
     * @throws BadRequestError|ValidationError
     * @throws \InvalidArgumentException for a video that is neither a Video nor a wire map
     */
    public static function decision(string $model, mixed $state, array $questions, array $images, array $limits, array $videos = [], array $boosters = []): void
    {
        $probe = ['model' => $model, 'state' => $state ?? '', 'questions' => (object) $questions];
        if ($boosters !== []) {
            $probe['boosters'] = $boosters;
        }
        if ($images !== []) {
            // Placeholders, so the nesting counts the images without their bytes.
            $probe['images'] = array_fill(0, count($images), ['id' => '']);
        }
        if ($videos !== []) {
            // A video and one frame, without their bytes, as the server counts them.
            $probe['videos'] = array_fill(0, count($videos), ['id' => '', 'frames' => [['t' => 0]]]);
        }
        self::nesting($probe);
        $entries = [];
        if ($state !== null && !self::sdl($state)) {
            array_push($entries, ...self::sdlEntries(['body', 'state'], self::json($state)));
        }
        $deferred = self::questionEntries($questions, $limits, $entries, self::imageIds($images));
        // More images or videos than a request takes join the same 422, after the questions' entries.
        $most = self::positive($limits['max_images'] ?? null) ?? self::positive(self::field($limits['images'] ?? null, 'max_images')) ?? self::MAX_IMAGES;
        foreach (['images' => [$images, $most], 'videos' => [$videos, self::MAX_VIDEOS]] as $field => [$list, $limit]) {
            if (count($list) > $limit) {
                $entries[] = self::entry(
                    'too_long',
                    ['body', $field],
                    sprintf('List should have at most %d %s after validation, not %d', $limit, $limit === 1 ? 'item' : 'items', count($list)),
                    self::json(array_map(self::countedWire(...), $list)),
                    ['field_type' => 'List', 'max_length' => $limit, 'actual_length' => count($list)],
                );
            }
        }
        self::raise($entries, $deferred);
        $ids = self::images($images, $limits);
        self::videos($videos, $ids);
    }

    /**
     * An encoded body over 25 MiB is the API's 413, raised before anything is sent.
     *
     * @throws RequestTooLargeError
     */
    public static function bodySize(int $bytes): void
    {
        if ($bytes > self::MAX_BODY_BYTES) {
            throw new RequestTooLargeError(
                'The request body is over 26,214,400 bytes (25 MiB). Send large images as uploads (POST /v1/uploads) and name them by upload_id.',
                errorType: 'request_too_large_error',
                reason: sprintf('the body is %d bytes; the limit is %d', $bytes, self::MAX_BODY_BYTES),
            );
        }
    }

    /**
     * An image or video as the count's entry shows it: its wire form, with a
     * local image's bytes left out.
     */
    private static function countedWire(mixed $item): mixed
    {
        if ($item instanceof Image && $item->isLocal()) {
            return ['id' => $item->id, 'media_type' => $item->mediaType];
        }

        return $item instanceof \JsonSerializable ? $item->jsonSerialize() : $item;
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
        self::questions(is_object($questions) ? get_object_vars($questions) : (array) $questions, $limits, $entries);
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
     * The items list's lookup: a `reference` (exact) or a `reference_prefix`,
     * never both, each 1 to 64 of `[A-Za-z0-9_.:-]`, checked after the
     * offset and the limit.
     *
     * @throws BadRequestError
     */
    public static function referenceQuery(?string $reference, ?string $prefix): void
    {
        if ($reference !== null && $prefix !== null) {
            throw self::bad('Send query parameter reference or reference_prefix, not both.');
        }
        foreach (['reference' => $reference, 'reference_prefix' => $prefix] as $name => $value) {
            if ($value !== null && preg_match('/' . self::ITEM_REFERENCE_PATTERN . '/D', $value) !== 1) {
                throw self::bad(sprintf('Query parameter %s must be 1 to 64 characters of A-Z, a-z, 0-9, _, ., : and -, got %s.', $name, self::printable($value, self::MAX_ITEM_REFERENCE_CHARS)));
            }
        }
    }

    /** The usage reads' `granularity` values. */
    public const USAGE_GRANULARITIES = ['none', 'hour', 'day', 'month'];
    /** The usage reads' `group_by` values. */
    public const USAGE_GROUPS = ['model', 'channel'];
    /** The kinds of key `usage->list()` filters by. */
    public const USAGE_SCOPES = ['full', 'decide', 'keys'];
    /** The key statuses `usage->list()` filters by. */
    public const USAGE_STATUSES = ['active', 'expired', 'revoked', 'all'];
    /** The most keys one usage page holds. */
    public const USAGE_PAGE_MAX = 1000;
    /** The most `key_id` filters one usage read takes. */
    public const USAGE_MAX_KEY_IDS = 100;

    /**
     * A usage read's `granularity` and `group_by`, as the API checks them
     * while it reads the query. The period itself (its rounding, at most 400
     * days, `hour` over at most 7) is the server's.
     *
     * @throws BadRequestError
     */
    public static function usagePeriod(?string $granularity, ?string $groupBy): void
    {
        if ($granularity !== null && !in_array($granularity, self::USAGE_GRANULARITIES, true)) {
            throw self::bad('granularity is none, hour, day or month.');
        }
        if ($groupBy !== null && !in_array($groupBy, self::USAGE_GROUPS, true)) {
            throw self::bad('group_by is model or channel.');
        }
    }

    /**
     * The filters of `usage->list()`, in the order the API reads them: each
     * label (by name) a name of 1 to 32 of `a-z 0-9 _ . -` and a value of 1
     * to 64 characters with no control character, the device id, each key id
     * and at most 100 of them, the scope, the status and the limit. The
     * cursor is the server's.
     *
     * @param array<array-key, mixed>|null $labels
     * @param array<array-key, mixed>|null $keyIds
     *
     * @throws BadRequestError
     */
    public static function usageFilters(?array $labels, ?string $deviceId, ?array $keyIds, ?string $scope, ?string $status, ?int $limit): void
    {
        foreach ($labels ?? [] as $name => $value) {
            if (!self::labelFilter($name, $value)) {
                throw self::bad('A label filter is name:value, as labels are written.');
            }
        }
        if ($deviceId !== null && !self::deviceIdOk($deviceId)) {
            throw self::bad(self::DEVICE_ID_RULE);
        }
        foreach (array_values($keyIds ?? []) as $i => $keyId) {
            if (!is_string($keyId) || preg_match('/^key_[0-9a-f]{32}$/D', $keyId) !== 1) {
                throw self::bad("key_id is a key's id, key_ and 32 hex digits.");
            }
            if ($i === self::USAGE_MAX_KEY_IDS) {
                throw self::bad('At most 100 key_id filters.');
            }
        }
        if ($scope !== null && !in_array($scope, self::USAGE_SCOPES, true)) {
            throw self::bad('scope is full, decide or keys.');
        }
        if ($status !== null && !in_array($status, self::USAGE_STATUSES, true)) {
            throw self::bad('status is active, expired, revoked or all.');
        }
        if ($limit !== null && ($limit < 1 || $limit > self::USAGE_PAGE_MAX)) {
            throw self::bad('limit is a whole number from 1 to 1000.');
        }
    }

    /** The most keys one page of `keys->list()` holds. */
    public const KEYS_PAGE_MAX = 200;
    /** The most labels a key carries. */
    public const KEYS_MAX_LABELS = 16;
    /** The most device ids one bulk revoke names. */
    public const KEYS_MAX_REVOKE_DEVICES = 1000;
    /** The longest key name, in characters, once trimmed. */
    public const KEY_NAME_CHARS = 48;

    private const DEVICE_ID_RULE = 'device_id is 1 to 128 printable ASCII characters, not starting with a space.';

    /**
     * A key's fields on `POST /v1/keys` and `PATCH /v1/keys/{id}`, the bounds
     * a client may hold, the first fault only, as the API answers it: the
     * name (when given) trimmed 1 to 48 characters, then in body order the
     * device id, the labels (an object of at most 16, each name 1 to 32 of
     * `a-z 0-9 _ . -`, each value 1 to 64 characters with no control
     * character), `limits` an object, and the daily spend cap from 0.01 to
     * 1,000 in whole cents. With `$clears` (an update) a field given as null
     * clears it and is not checked. A limit's ceiling, the expiry's window
     * and a name's characters are the server's.
     *
     * @param array<string, mixed> $fields the fields given beyond the name, in body order; the cap by its USD amount
     *
     * @throws ValidationError
     */
    public static function keyFields(bool $hasName, mixed $name, array $fields, bool $clears): void
    {
        $entries = $hasName ? self::keyNameEntries($name) : [];
        if ($entries === []) {
            foreach ($fields as $field => $value) {
                if ($clears && $value === null && $field !== 'device_id') {
                    continue;
                }
                switch ($field) {
                    case 'device_id':
                        if (!self::deviceIdOk($value)) {
                            $entries[] = self::entry('value_error', ['body', 'device_id'], self::DEVICE_ID_RULE, self::echoed($value));
                        }
                        break;
                    case 'labels':
                        array_push($entries, ...self::keyLabelsEntries($value));
                        break;
                    case 'limits':
                        if (!self::isMap($value)) {
                            $entries[] = self::entry('value_error', ['body', 'limits'], 'limits is an object, or null.', self::echoed($value));
                        }
                        break;
                    case 'daily_spend_cap':
                        if (!self::capOk($value)) {
                            // The cap is sent as an object, which is never echoed.
                            $entries[] = self::entry('value_error', ['body', 'daily_spend_cap'], 'daily_spend_cap.usd is from 0.01 to 1000, in cents, or null.', null);
                        }
                        break;
                }
            }
        }
        if ($entries !== []) {
            self::raise([$entries[0]], null);
        }
    }

    /**
     * `POST /v1/keys/revoke`: the labels as a key's, at least one of them,
     * then at most 1,000 device ids, each as a key's; the first fault only.
     *
     * @param array<array-key, mixed>|null $deviceIds
     *
     * @throws ValidationError
     */
    public static function keyRevoke(mixed $labels, ?array $deviceIds): void
    {
        $entries = self::keyLabelsEntries($labels);
        if ($entries === [] && $labels === []) {
            $entries[] = self::entry('value_error', ['body', 'labels'], 'A bulk revoke names at least one label: every key it revokes has them all.', null);
        }
        if ($entries === [] && $deviceIds !== null) {
            if (count($deviceIds) > self::KEYS_MAX_REVOKE_DEVICES) {
                $entries[] = self::entry('value_error', ['body', 'device_ids'], 'device_ids is a list of at most 1,000 device ids.', null);
            } else {
                foreach ($deviceIds as $deviceId) {
                    if (!self::deviceIdOk($deviceId)) {
                        $entries[] = self::entry('value_error', ['body', 'device_id'], self::DEVICE_ID_RULE, self::echoed($deviceId));
                        break;
                    }
                }
            }
        }
        if ($entries !== []) {
            self::raise([$entries[0]], null);
        }
    }

    /**
     * The query of `keys->list()`, as the API reads it: each label filter
     * (by name) and the device id as the usage reads take them, the status,
     * and a limit from 1 to 200. The cursor is the server's.
     *
     * @param array<array-key, mixed>|null $labels
     *
     * @throws BadRequestError
     */
    public static function keyList(?array $labels, ?string $deviceId, ?string $status, ?int $limit): void
    {
        self::usageFilters($labels, $deviceId, null, null, $status, null);
        if ($limit !== null && ($limit < 1 || $limit > self::KEYS_PAGE_MAX)) {
            throw self::bad('limit is a whole number from 1 to 200.');
        }
    }

    /**
     * An Idempotency-Key given by the caller: 1 to 255 printable ASCII
     * characters, as the API takes it.
     *
     * @throws BadRequestError
     */
    public static function idempotencyKey(?string $key): void
    {
        if ($key !== null && (strlen($key) < 1 || strlen($key) > 255 || preg_match('/^[\x20-\x7E]+$/D', $key) !== 1)) {
            throw self::bad('Idempotency-Key must be sent once, as 1 to 255 printable ASCII characters.');
        }
    }

    /**
     * A key name's entries: trimmed, it is 1 to 48 characters. Its character
     * set is the server's.
     *
     * @return list<array<string, mixed>>
     */
    private static function keyNameEntries(mixed $name): array
    {
        $trimmed = is_string($name) && mb_check_encoding($name, 'UTF-8') ? (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $name) : '';
        if ($trimmed === '') {
            return [self::entry('value_error', ['body', 'name'], 'Give the key a name.', self::echoed($name))];
        }
        if (mb_strlen($trimmed, 'UTF-8') > self::KEY_NAME_CHARS) {
            return [self::entry('value_error', ['body', 'name'], 'Key names are at most 48 characters.', self::echoed($name))];
        }

        return [];
    }

    /**
     * A key's labels' entry: an object of at most 16, then, in order, each
     * name 1 to 32 of `a-z 0-9 _ . -` and each value 1 to 64 characters
     * with no control character.
     *
     * @return list<array<string, mixed>>
     */
    private static function keyLabelsEntries(mixed $labels): array
    {
        if (!self::isMap($labels)) {
            return [self::entry('value_error', ['body', 'labels'], 'labels is an object of names to values.', self::echoed($labels))];
        }
        /** @var array<array-key, mixed> $labels */
        if (count($labels) > self::KEYS_MAX_LABELS) {
            return [self::entry('value_error', ['body', 'labels'], 'A key has at most 16 labels.', null)];
        }
        foreach ($labels as $name => $value) {
            $name = (string) $name;
            if (preg_match('/^[a-z0-9_.-]{1,32}$/D', $name) !== 1) {
                return [self::entry('value_error', ['body', 'labels'], "A label's name is 1 to 32 of a-z, 0-9, _ . and -.", null)];
            }
            if (!self::labelFilter($name, $value)) {
                return [self::entry('value_error', ['body', 'labels', $name], "A label's value is 1 to 64 characters of text.", null)];
            }
        }

        return [];
    }

    /** Whether a value is sent as a JSON object: a map, an empty array or an object. */
    private static function isMap(mixed $value): bool
    {
        return $value instanceof \stdClass || (is_array($value) && ($value === [] || !array_is_list($value)));
    }

    /** A device id: 1 to 128 printable ASCII characters, not starting with a space. */
    private static function deviceIdOk(mixed $value): bool
    {
        return is_string($value) && strlen($value) >= 1 && strlen($value) <= 128 && preg_match('/^[\x20-\x7E]+$/D', $value) === 1 && $value[0] !== ' ';
    }

    /** A daily spend cap in USD: from 0.01 to 1,000, in whole cents. */
    private static function capOk(mixed $usd): bool
    {
        if ((!is_int($usd) && !is_float($usd)) || !is_finite((float) $usd)) {
            return false;
        }
        $cents = round($usd * 100);

        return abs($usd * 100 - $cents) < 1e-6 && $cents >= 1 && $usd <= 1000;
    }

    /**
     * Whether a label filter is written as labels are: a name of 1 to 32 of
     * `a-z 0-9 _ . -` and a value of 1 to 64 characters with no control
     * character.
     */
    private static function labelFilter(mixed $name, mixed $value): bool
    {
        if (!is_string($name) || preg_match('/^[a-z0-9_.-]{1,32}$/D', $name) !== 1 || !is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return false;
        }
        $chars = mb_strlen($value, 'UTF-8');

        return $chars >= 1 && $chars <= 64 && preg_match('/\p{Cc}/u', $value) !== 1;
    }

    /**
     * A caller's value as an API message echoes it: its first `$most`
     * characters, any but printable ASCII as `\u{hex}`, then "..." when it
     * was longer.
     */
    private static function printable(string $value, int $most): string
    {
        $chars = mb_str_split($value, 1, 'UTF-8');
        $out = '';
        foreach (array_slice($chars, 0, $most) as $c) {
            $code = mb_ord($c, 'UTF-8');
            $out .= $code >= 0x20 && $code <= 0x7E ? $c : sprintf('\\u{%x}', $code);
        }

        return $out . (count($chars) > $most ? '...' : '');
    }

    /**
     * `POST /v1/collections`: the type is `face` or `text`, the name 1 to 80
     * characters, and a scope key, when given, 1 to 40 of `[a-z0-9_]`. The
     * faults are reported together.
     *
     * @throws ValidationError
     */
    public static function collection(mixed $type, mixed $name, ?string $scopeKey = null): void
    {
        $entries = [];
        if ($type !== 'face' && $type !== 'text') {
            $entries[] = self::entry('literal_error', ['body', 'type'], "Input should be 'face' or 'text'", self::echoed($type), ['expected' => "'face' or 'text'"]);
        }
        array_push($entries, ...self::stringEntries(['body', 'name'], $name, self::MAX_COLLECTION_NAME_CHARS));
        if ($scopeKey !== null && preg_match('/' . self::SCOPE_KEY_PATTERN . '/D', $scopeKey) !== 1) {
            $entries[] = self::entry('string_pattern_mismatch', ['body', 'scope_key'], sprintf("String should match pattern '%s'", self::SCOPE_KEY_PATTERN), self::echoed($scopeKey));
        }
        self::raise($entries, null);
    }

    /** A knowledge base's scope key. */
    public const SCOPE_KEY_PATTERN = '^[a-z0-9_]{1,40}$';

    /** A document's title, trimmed, in characters. */
    public const MAX_TITLE_CHARS = 200;

    public const MAX_PIN_RANK = 1000;

    /**
     * A document's item body (`POST` and `PUT` of a knowledge base's item):
     * the title, an empty text and the format, then the reference, the pin
     * rank, the label and the metadata, all reported together. `$title` is
     * the title to send (the one given, or a file's stem), `$titleNeeded`
     * whether the body must carry one; `$text` the text sent inline, or
     * null for a document sent by upload id.
     *
     * @param array<array-key, mixed>|null $metadata
     *
     * @throws ValidationError
     */
    public static function document(?string $title, bool $titleNeeded, ?string $text, ?string $format, ?string $reference, ?int $pinRank, ?string $label, ?array $metadata): void
    {
        $entries = self::titleEntries($title, $titleNeeded);
        if ($text === '') {
            $entries[] = self::entry('string_too_short', ['body', 'text'], 'The text is empty', '');
        }
        if ($format !== null && $format !== 'markdown' && $format !== 'text') {
            $entries[] = self::entry('literal_error', ['body', 'format'], "Input should be 'markdown' or 'text'", self::echoed($format));
        }
        array_push($entries, ...self::textFieldEntries($reference !== null, $reference, $pinRank, $label !== null, $label, $metadata !== null, $metadata));
        self::raise($entries, null);
    }

    /**
     * A title's 422 entry: missing where one is needed, or not 1 to 200
     * characters once trimmed, counted in code points.
     *
     * @return list<array<string, mixed>>
     */
    private static function titleEntries(?string $title, bool $needed): array
    {
        if ($title === null) {
            return $needed ? [self::entry('missing', ['body', 'title'], 'Field required', null)] : [];
        }
        $chars = mb_strlen(self::trimmed($title), 'UTF-8');
        if ($chars < 1 || $chars > self::MAX_TITLE_CHARS) {
            return [self::entry('string_too_long', ['body', 'title'], sprintf('A title is 1 to %d characters', self::MAX_TITLE_CHARS), self::echoed($title))];
        }

        return [];
    }

    /**
     * A text without the white space at its ends, as the API trims a title
     * (Unicode white space; a text that is not UTF-8 as it is).
     */
    public static function trimmed(string $text): string
    {
        return preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $text) ?? $text;
    }

    /**
     * A knowledge base item's shared fields: the reference, the label and
     * the metadata as an enrollment's, and the pin rank, 0 to 1,000.
     *
     * @return list<array<string, mixed>>
     */
    private static function textFieldEntries(bool $hasReference, mixed $reference, ?int $pinRank, bool $hasLabel, mixed $label, bool $hasMetadata, mixed $metadata): array
    {
        $entries = [];
        if ($hasReference) {
            array_push($entries, ...self::stringEntries(['body', 'reference'], $reference, self::MAX_ITEM_REFERENCE_CHARS, self::ITEM_REFERENCE_PATTERN));
        }
        if ($pinRank !== null && ($pinRank < 0 || $pinRank > self::MAX_PIN_RANK)) {
            $entries[] = self::entry('int_range', ['body', 'pin_rank'], sprintf('Input should be an integer from 0 to %d', self::MAX_PIN_RANK), $pinRank);
        }
        if ($hasLabel && $label !== null) {
            array_push($entries, ...self::stringEntries(['body', 'label'], $label, self::MAX_ITEM_LABEL_CHARS));
        }
        if ($hasMetadata) {
            array_push($entries, ...self::metadataEntries($metadata));
        }

        return $entries;
    }

    /**
     * `POST /v1/collections/{id}/items`: an image, a reference of 1 to 64 of
     * `[A-Za-z0-9_.:-]` and a label of at most 128 characters, then an image
     * URL's rules, as a decision's.
     *
     * @throws BadRequestError|ValidationError
     */
    public static function enrollment(mixed $image, mixed $reference, mixed $label, mixed $metadata = null): void
    {
        $entries = [];
        if ($image === null) {
            $entries[] = self::entry('missing', ['body', 'image'], 'Field required', null);
        }
        // Its length first, then the pattern.
        array_push($entries, ...self::stringEntries(['body', 'reference'], $reference, self::MAX_ITEM_REFERENCE_CHARS, self::ITEM_REFERENCE_PATTERN));
        if ($label !== null) {
            array_push($entries, ...self::stringEntries(['body', 'label'], $label, self::MAX_ITEM_LABEL_CHARS));
        }
        if ($metadata !== null) {
            array_push($entries, ...self::metadataEntries($metadata));
        }
        self::raise($entries, null);
        // Only a URL's rules are a decision's; every other fault of the
        // picture is the server's 422 image_unreadable.
        if ($image instanceof Image && $image->url !== null) {
            self::url($image->id, $image->url);
        } elseif (is_array($image) && is_string($image['url'] ?? null)) {
            self::url(is_string($image['id'] ?? null) ? $image['id'] : '', $image['url']);
        }
    }

    /**
     * `PATCH /v1/collections/{id}/items/{item_id}`: at least one field, the
     * reference and the label checked as an enrollment's, a label given as
     * null clearing it, and the metadata. Each `$has...` says whether that
     * field was given. A change of `pinned`, `pin_rank` or `title` is a
     * knowledge base's: the pin rank 0 to 1,000 and the title 1 to 200
     * characters are checked too.
     *
     * @throws ValidationError
     */
    public static function itemUpdate(bool $hasReference, mixed $reference, bool $hasLabel, mixed $label, bool $hasMetadata, mixed $metadata, bool $hasPinned = false, ?int $pinRank = null, ?string $title = null): void
    {
        if ($hasPinned || $pinRank !== null || $title !== null) {
            $entries = self::textFieldEntries($hasReference, $reference, $pinRank, $hasLabel, $label, $hasMetadata, $metadata);
            array_push($entries, ...self::titleEntries($title, false));
            self::raise($entries, null);

            return;
        }
        $entries = [];
        if ($hasReference) {
            array_push($entries, ...self::stringEntries(['body', 'reference'], $reference, self::MAX_ITEM_REFERENCE_CHARS, self::ITEM_REFERENCE_PATTERN));
        }
        if ($hasLabel && $label !== null) {
            array_push($entries, ...self::stringEntries(['body', 'label'], $label, self::MAX_ITEM_LABEL_CHARS));
        }
        if ($hasMetadata) {
            array_push($entries, ...self::metadataEntries($metadata));
        }
        if (!$hasReference && !$hasLabel && !$hasMetadata) {
            $entries[] = self::entry('missing', ['body'], 'Send reference, label, metadata or any of them', null);
        }
        self::raise($entries, null);
    }

    /**
     * An item's metadata's 422 entries, the limits a client may hold: an
     * object of at most 20 keys (that alone, before any key is read), then,
     * in key order, each key 1 to 40 of `[a-z0-9_]` and each value a text
     * of at most 500 characters, a number, or true or false. A text's
     * control characters, a number's range and the whole object's size are
     * the server's to judge.
     *
     * @return list<array<string, mixed>>
     */
    private static function metadataEntries(mixed $metadata): array
    {
        $loc = ['body', 'metadata'];
        if ($metadata instanceof \stdClass) {
            $metadata = get_object_vars($metadata);
        }
        if (!is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) {
            return [self::entry('value_error', $loc, 'Metadata is an object of keys and values.', self::echoed($metadata))];
        }
        if (count($metadata) > self::MAX_METADATA_KEYS) {
            return [self::entry('value_error', $loc, sprintf('Metadata holds at most %d keys.', self::MAX_METADATA_KEYS), null)];
        }
        $out = [];
        foreach ($metadata as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[a-z0-9_]{1,40}$/D', $key) !== 1) {
                $chars = mb_str_split($key, 1, 'UTF-8');
                $shown = '';
                foreach (array_slice($chars, 0, self::MAX_METADATA_KEY_CHARS) as $c) {
                    $shown .= self::isControl($c) ? '?' : $c;
                }
                if (count($chars) > self::MAX_METADATA_KEY_CHARS) {
                    $shown .= '...';
                }
                $out[] = self::entry('value_error', $loc, sprintf('A metadata key is 1 to %d characters of a-z, 0-9 and _: "%s".', self::MAX_METADATA_KEY_CHARS, $shown), null);
            } elseif (is_string($value)) {
                if (mb_strlen($value, 'UTF-8') > self::MAX_METADATA_TEXT_CHARS) {
                    $out[] = self::entry('value_error', [...$loc, $key], sprintf('A metadata text is at most %d characters.', self::MAX_METADATA_TEXT_CHARS), self::echoed($value));
                }
            } elseif (!is_int($value) && !is_float($value) && !is_bool($value)) {
                $out[] = self::entry('value_error', [...$loc, $key], 'A metadata value is a text, a number or true or false.', self::echoed($value));
            }
        }

        return $out;
    }

    /** Whether a character is a control character (Unicode category Cc). */
    private static function isControl(string $c): bool
    {
        return preg_match('/^\p{Cc}$/u', $c) === 1;
    }

    /**
     * An input as a collections 422 entry echoes it, as the API does: a
     * string cut to its first 64 characters, with "..." when it was longer;
     * an object or an array as null; anything else as it is.
     */
    private static function echoed(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value, 'UTF-8') > self::ECHO_CHARS ? mb_substr($value, 0, self::ECHO_CHARS, 'UTF-8') . '...' : $value;
        }
        if (is_array($value) || is_object($value)) {
            return null;
        }

        return $value;
    }

    /**
     * A collections string field's 422 entry, as the API answers it:
     * missing, not a string, empty, longer than `$most` characters, or not
     * matching `$pattern`, in that order, its input echoed as the API
     * echoes it.
     *
     * @param list<string|int> $loc
     *
     * @return list<array<string, mixed>>
     */
    private static function stringEntries(array $loc, mixed $value, int $most, ?string $pattern = null): array
    {
        if ($value === null) {
            return [self::entry('missing', $loc, 'Field required', null)];
        }
        if (!is_string($value)) {
            return [self::entry('string_type', $loc, 'Input should be a valid string', self::echoed($value))];
        }
        if ($value === '') {
            return [self::entry('string_too_short', $loc, 'String should have at least 1 character', '', ['min_length' => 1])];
        }
        if (mb_strlen($value, 'UTF-8') > $most) {
            return [self::entry('string_too_long', $loc, sprintf('String should have at most %d characters', $most), self::echoed($value), ['max_length' => $most])];
        }
        if ($pattern !== null && preg_match('/' . $pattern . '/D', $value) !== 1) {
            return [self::entry('string_pattern_mismatch', $loc, sprintf("String should match pattern '%s'", $pattern), $value, ['pattern' => $pattern])];
        }

        return [];
    }

    /**
     * The image rules: ids, and each image's own rules. How many is checked
     * with the request's other 422 entries.
     *
     * @param list<mixed>          $images Image objects or wire maps
     * @param array<string, mixed> $limits
     *
     * @return array<string, true> the image ids
     *
     * @throws BadRequestError
     */
    public static function images(array $images, array $limits = []): array
    {
        $seen = [];
        foreach ($images as $i => $image) {
            $id = $image instanceof Image ? $image->id : self::field($image, 'id');
            if (!is_string($id) || $id === '') {
                throw self::bad(sprintf('Image %d needs an id.', $i));
            }
            if (mb_strlen($id, 'UTF-8') > self::MAX_IMAGE_ID_CHARS) {
                throw self::bad(sprintf('Image %d: its id is longer than %d characters.', $i, self::MAX_IMAGE_ID_CHARS));
            }
            if (isset($seen[$id])) {
                throw self::bad(sprintf('Duplicate image id: %s', $id));
            }
            $seen[$id] = true;
            if ($image instanceof Image) {
                self::image($image, $limits);
            }
        }

        return $seen;
    }

    /**
     * The video rules, in the server's order, each video in turn: its id,
     * unique across the images and videos, its frames, each frame's time and
     * media type, then fps and the window. How many videos is checked with
     * the request's other 422 entries; a frame's bytes, the frame counts and
     * the pixels are the server's to judge.
     *
     * @param list<mixed>         $videos   Video objects or wire maps
     * @param array<string, true> $imageIds the request's image ids
     *
     * @throws BadRequestError
     * @throws \InvalidArgumentException for a video that is neither a Video nor a wire map
     */
    public static function videos(array $videos, array $imageIds = []): void
    {
        $seen = $imageIds;
        foreach ($videos as $i => $video) {
            if ($video instanceof Video) {
                $fields = ['id' => $video->id, 'frames' => $video->frames, 'fps' => $video->fps, 'start' => $video->start, 'end' => $video->end];
            } elseif (is_array($video) || is_object($video)) {
                $fields = [];
                foreach (['id', 'frames', 'fps', 'start', 'end'] as $name) {
                    $fields[$name] = self::field($video, $name);
                }
            } else {
                throw new \InvalidArgumentException(sprintf('Video %d must be a Video or a wire-form map, got %s.', $i, get_debug_type($video)));
            }
            $id = $fields['id'];
            if (!is_string($id) || $id === '') {
                throw self::bad(sprintf('Video %d needs an id.', $i));
            }
            $name = self::repr($id);
            if (isset($seen[$id])) {
                throw self::bad(sprintf('Video id %s is used twice (ids are unique across images and videos).', $name));
            }
            $seen[$id] = true;
            $frames = $fields['frames'];
            if (!is_array($frames) || $frames === [] || !array_is_list($frames)) {
                throw self::bad(sprintf('Video %s: frames must be a non-empty list of frames.', $name));
            }
            $before = null;
            foreach ($frames as $n => $frame) {
                if ($frame instanceof VideoFrame) {
                    [$t, $data, $mediaType] = [$frame->t, $frame->base64 ?? $frame->data, $frame->mediaType];
                } elseif (is_array($frame) || is_object($frame)) {
                    [$t, $data, $mediaType] = [self::field($frame, 't'), self::field($frame, 'data'), self::field($frame, 'media_type')];
                } else {
                    throw self::bad(sprintf('Video %s: frame %d must be an object with t, media_type and data.', $name, $n));
                }
                if (!self::finite($t) || $t < 0) {
                    throw self::bad(sprintf('Video %s: frame %d: t must be a number of seconds, at least 0.', $name, $n));
                }
                if ($before !== null && $t <= $before) {
                    throw self::bad(sprintf('Video %s: frame %d: t must be larger than the frame before it (%s).', $name, $n, self::shortNumber($before)));
                }
                $before = $t;
                if (!is_string($data)) {
                    throw self::bad(sprintf('Video %s: frame %d: data must be base64 text.', $name, $n));
                }
                if (!in_array($mediaType, self::MEDIA_TYPES, true)) {
                    throw self::bad(sprintf('Video %s: frame %d: media_type must be one of %s.', $name, $n, implode(', ', self::MEDIA_TYPES)));
                }
            }
            $fps = $fields['fps'];
            if ($fps !== null && (!self::finite($fps) || $fps <= 0 || $fps > self::MAX_FPS)) {
                throw self::bad(sprintf('Video %s: fps must be a number above 0 and at most %d.', $name, self::MAX_FPS));
            }
            foreach (['start', 'end'] as $bound) {
                $value = $fields[$bound];
                if ($value !== null && (!self::finite($value) || $value < 0)) {
                    throw self::bad(sprintf('Video %s: %s must be a number of seconds, at least 0.', $name, $bound));
                }
            }
            if ($fields['end'] !== null && $fields['end'] <= ($fields['start'] ?? 0)) {
                throw self::bad(sprintf('Video %s: end must be after start.', $name));
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
     * The ids of a request's images as given, whatever their form.
     *
     * @param list<mixed> $images
     *
     * @return list<string>
     */
    private static function imageIds(array $images): array
    {
        $ids = [];
        foreach ($images as $image) {
            $id = $image instanceof Image ? $image->id : self::field($image, 'id');
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<array-key, mixed>    $questions
     * @param array<string, mixed>       $limits
     * @param list<array<string, mixed>> $entries   422 entries found before the questions
     * @param list<string>|null          $imageIds  the request's image ids; null where a request carries no images
     *                                              of its own (a session's open), so a point or box is not held to them
     *
     * @throws BadRequestError|ValidationError
     */
    private static function questions(array $questions, array $limits, array $entries, ?array $imageIds = null): void
    {
        self::raise($entries, self::questionEntries($questions, $limits, $entries, $imageIds));
    }

    /**
     * Adds every question's 422 entries and returns the first 400, if any.
     *
     * @param array<array-key, mixed>    $questions
     * @param array<string, mixed>       $limits
     * @param list<array<string, mixed>> $entries
     * @param list<string>|null          $imageIds
     */
    private static function questionEntries(array $questions, array $limits, array &$entries, ?array $imageIds): ?BadRequestError
    {
        $deferred = null;
        foreach ($questions as $id => $question) {
            $found = self::question((string) $id, self::json($question), $limits, $entries, $imageIds);
            $deferred ??= $found;
        }

        return $deferred;
    }

    /**
     * The 422 when there are entries, else the first 400, else nothing.
     *
     * @param list<array<string, mixed>> $entries
     *
     * @throws BadRequestError|ValidationError
     */
    private static function raise(array $entries, ?BadRequestError $deferred): void
    {
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
     * @param list<string>|null          $imageIds
     */
    private static function question(string $id, mixed $q, array $limits, array &$entries, ?array $imageIds): ?BadRequestError
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
        if (!is_string($type) || !in_array($type, self::DECIDE_TYPES, true)) {
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
            return self::score($q, $criteria, $loc, $entries, self::positive($limits['max_score_levels'] ?? null) ?? self::MAX_SCORE_LEVELS);
        }
        if ($type === 'point' || $type === 'box') {
            return self::place($id, $type, $q, $loc, $entries, $imageIds);
        }

        // truth, and its older wire name, which the API still takes in a question sent as a plain map
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
     * A point or box question: an image to look in, named by `image` when
     * the request has two or more, and `units` of `pixels` or `normalized`;
     * then its instructions, whose absence is a plain-string 400.
     *
     * @param list<string|int>           $loc
     * @param list<array<string, mixed>> $entries
     * @param list<string>|null          $imageIds
     */
    private static function place(string $id, string $type, \stdClass $q, array $loc, array &$entries, ?array $imageIds): ?BadRequestError
    {
        $image = $q->image ?? null;
        if ($imageIds !== null) {
            if ($imageIds === []) {
                $entries[] = self::entry('image_required', $loc, sprintf('A %s question needs an image in the request', $type), $q);
            } elseif ($image === null) {
                if (count($imageIds) >= 2) {
                    $entries[] = self::entry('missing', [...$loc, 'image'], 'Field required', $q);
                }
            } elseif (!is_string($image)) {
                $entries[] = self::entry('string_type', [...$loc, 'image'], 'Input should be a valid string', $image);
            } elseif (!in_array($image, $imageIds, true)) {
                $entries[] = self::entry('unknown_image', [...$loc, 'image'], 'Input should be the id of an image of the request', $image);
            }
        }
        $units = $q->units ?? null;
        if ($units !== null && !in_array($units, self::UNITS, true)) {
            $entries[] = self::entry('literal_error', [...$loc, 'units'], "Input should be 'pixels' or 'normalized'", $units, ['expected' => "'pixels' or 'normalized'"]);
        }
        // Several places: max an integer from 2 to 16.
        $most = $q->max ?? null;
        if ($most !== null) {
            if (!is_int($most)) {
                $entries[] = self::entry('int_type', [...$loc, 'max'], 'Input should be a valid integer', $most);
            } elseif ($most < self::MIN_PLACES) {
                $entries[] = self::entry('greater_than_equal', [...$loc, 'max'], sprintf('Input should be greater than or equal to %d', self::MIN_PLACES), $most, ['ge' => self::MIN_PLACES]);
            } elseif ($most > self::MAX_PLACES) {
                $entries[] = self::entry('less_than_equal', [...$loc, 'max'], sprintf('Input should be less than or equal to %d', self::MAX_PLACES), $most, ['le' => self::MAX_PLACES]);
            }
        }
        if (($q->instructions ?? null) === null) {
            return self::plain(sprintf('%s question must have instructions: %s', ucfirst($type), $id));
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
     * Levels are a list, or an object keyed "0" to "K-1".
     *
     * @param list<string|int>           $loc
     * @param list<array<string, mixed>> $entries
     */
    private static function score(\stdClass $q, mixed $criteria, array $loc, array &$entries, int $most): ?BadRequestError
    {
        if ($criteria === null) {
            $entries[] = self::entry('missing', [...$loc, 'criteria'], 'Field required', $q);

            return null;
        }
        $levels = null;
        if (is_array($criteria)) {
            $levels = $criteria;
        } elseif ($criteria instanceof \stdClass) {
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

    /**
     * A JSON number (never a boolean) that is finite.
     *
     * @phpstan-assert-if-true int|float $value
     */
    private static function finite(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value));
    }

    /**
     * A string as the server quotes it in a message: in single quotes, or in
     * double quotes when it holds a single quote and no double quote, with
     * backslashes, the quote and control characters escaped.
     */
    private static function repr(string $text): string
    {
        $quote = str_contains($text, "'") && !str_contains($text, '"') ? '"' : "'";
        $out = '';
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            $code = mb_ord($char, 'UTF-8');
            $out .= match (true) {
                $char === '\\' => '\\\\',
                $char === $quote => '\\' . $quote,
                $char === "\n" => '\\n',
                $char === "\r" => '\\r',
                $char === "\t" => '\\t',
                $code < 0x20 || ($code >= 0x7F && $code < 0xA0) => sprintf('\\x%02x', $code),
                default => $char,
            };
        }

        return $quote . $out . $quote;
    }

    /**
     * A number as the server writes it in a message, printf's `%g`: six
     * significant digits without trailing zeros, in exponent form below
     * 0.0001 and from 1,000,000 (`1.5`, `4`, `1000.25`, `1e+06`, `1e-05`).
     */
    private static function shortNumber(int|float $value): string
    {
        if ($value == 0) {
            return '0';
        }
        $sci = sprintf('%.5e', $value);
        [$mantissa, $exponent] = explode('e', $sci);
        $exponent = (int) $exponent;
        if ($exponent >= -4 && $exponent < 6) {
            $text = sprintf('%.' . (5 - $exponent) . 'f', $value);

            return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
        }
        $mantissa = str_contains($mantissa, '.') ? rtrim(rtrim($mantissa, '0'), '.') : $mantissa;

        return sprintf('%se%s%02d', $mantissa, $exponent < 0 ? '-' : '+', abs($exponent));
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
