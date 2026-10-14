<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\ServerError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\Internal\Api;
use Bynn\DecisionNode\Internal\Json;
use Bynn\DecisionNode\Internal\LocalChecks;
use Bynn\DecisionNode\Internal\Query;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * The Keys API: Decisions-only keys for your devices, made, listed, changed
 * and revoked from your provisioning server with a Manage device keys key
 * (made in the console; any other kind of key gets a PermissionDeniedError,
 * `key_scope_denied`). Reach it as `$dn->keys`.
 *
 *     $made = $dn->keys->create('Kiosk 0412', deviceId: 'kiosk-0412', labels: ['store' => '118'],
 *         dailySpendCapUsd: 2.0, idempotencyKey: 'enroll-kiosk-0412');
 *     // hand $made->secret to the device once; keep $made->key->id
 *     $dn->keys->revokeByLabels(['store' => '118']);
 *
 * The fixed rules are checked before anything is sent, the first fault
 * raised as the API would answer it: a name's length, a device id, the
 * labels, the shape of `limits`, the daily spend cap, an update with nothing
 * to change, an Idempotency-Key, the list's filters and limit, a bulk revoke
 * without a label or with over 1,000 devices. A limit's ceiling, the
 * expiry's window and a name's characters are the server's. Every result
 * holds the API's fields and `raw`. `create` sends an
 * Idempotency-Key, yours or a new UUID for each call, the same on every
 * attempt, so the server makes one key however often the call is tried: it
 * is retried like every API call, and on a 409 idempotency_in_progress after
 * its wait. The other calls are retried like every API call.
 */
final class Keys
{
    /** An argument left out, told apart from null. */
    private const UNSET = "\0unset";

    /**
     * @internal
     */
    public function __construct(private readonly Api $api)
    {
    }

    /**
     * `POST /v1/keys`: a new Decisions-only key and its secret, shown once.
     * `labels` are name => value; `limits` holds `requests_per_second` and
     * `tokens_per_second`; `dailySpendCapUsd` is sent as `{"usd": ...}`;
     * `expiresAt` is RFC 3339. Each is sent only when given. Give an
     * `idempotencyKey` of the enrollment (1 to 255 printable ASCII
     * characters), so a retry within 24 hours, yours or the client's,
     * returns the same key and secret.
     *
     * @param array<string, string>|null $labels
     * @param array<string, int>|null    $limits
     *
     * @throws ValidationError|BadRequestError|DecisionNodeError
     */
    public function create(
        string $name,
        ?string $deviceId = null,
        ?array $labels = null,
        ?array $limits = null,
        int|float|null $dailySpendCapUsd = null,
        ?string $expiresAt = null,
        ?bool $maySearchCollections = null,
        ?string $idempotencyKey = null,
    ): CreatedKey {
        return $this->createAsync($name, $deviceId, $labels, $limits, $dailySpendCapUsd, $expiresAt, $maySearchCollections, $idempotencyKey)->wait();
    }

    /**
     * @param array<string, string>|null $labels
     * @param array<string, int>|null    $limits
     *
     * @return PromiseInterface resolves with a CreatedKey
     */
    public function createAsync(
        string $name,
        ?string $deviceId = null,
        ?array $labels = null,
        ?array $limits = null,
        int|float|null $dailySpendCapUsd = null,
        ?string $expiresAt = null,
        ?bool $maySearchCollections = null,
        ?string $idempotencyKey = null,
    ): PromiseInterface {
        $fields = array_filter(
            ['device_id' => $deviceId, 'labels' => $labels, 'limits' => $limits, 'daily_spend_cap' => $dailySpendCapUsd],
            static fn (mixed $value): bool => $value !== null,
        );
        LocalChecks::keyFields(true, $name, $fields, false);
        LocalChecks::idempotencyKey($idempotencyKey);
        $body = ['name' => $name];
        if ($deviceId !== null) {
            $body['device_id'] = $deviceId;
        }
        if ($labels !== null) {
            $body['labels'] = self::map($labels);
        }
        if ($limits !== null) {
            $body['limits'] = self::map($limits);
        }
        if ($dailySpendCapUsd !== null) {
            $body['daily_spend_cap'] = ['usd' => $dailySpendCapUsd];
        }
        if ($expiresAt !== null) {
            $body['expires_at'] = $expiresAt;
        }
        if ($maySearchCollections !== null) {
            $body['may_search_collections'] = $maySearchCollections;
        }

        return $this->request('POST', '/v1/keys', Json::encode($body), Api::RETRY_IDEMPOTENT, $idempotencyKey ?? Api::idempotencyKey())->then(
            static fn (array $answer): CreatedKey => CreatedKey::fromArray($answer),
        );
    }

    /**
     * `GET /v1/keys`: one page of the workspace's Decisions-only keys,
     * newest first. `labels` (name => value) must all match, sent as
     * `label=name:value` by name; `deviceId` finds one device's key;
     * `status` is `active` (the server's default), `expired`, `revoked` or
     * `all`; `limit` is 1 to 200; `cursor` is the `nextCursor` of the page
     * before. Each is sent only when given. There is no call for one key by
     * its id: find it by its `deviceId` or labels.
     *
     * @param array<string, string>|null $labels
     *
     * @throws BadRequestError|DecisionNodeError
     */
    public function list(?array $labels = null, ?string $deviceId = null, ?string $status = null, ?int $limit = null, ?string $cursor = null): KeyPage
    {
        return $this->listAsync($labels, $deviceId, $status, $limit, $cursor)->wait();
    }

    /**
     * @param array<string, string>|null $labels
     *
     * @return PromiseInterface resolves with a KeyPage
     */
    public function listAsync(?array $labels = null, ?string $deviceId = null, ?string $status = null, ?int $limit = null, ?string $cursor = null): PromiseInterface
    {
        LocalChecks::keyList($labels, $deviceId, $status, $limit);
        $pairs = Query::labels($labels);
        foreach (['device_id' => $deviceId, 'status' => $status, 'limit' => $limit === null ? null : (string) $limit, 'cursor' => $cursor] as $name => $value) {
            if ($value !== null) {
                $pairs[] = [$name, $value];
            }
        }

        return $this->request('GET', '/v1/keys' . Query::encode($pairs))->then(
            static fn (array $answer): KeyPage => KeyPage::fromArray($answer),
        );
    }

    /**
     * Every key `list()` would list: its pages, `nextCursor` followed until
     * it is null, their items in order.
     *
     * @param array<string, string>|null $labels
     *
     * @return list<Key>
     *
     * @throws DecisionNodeError
     */
    public function all(?array $labels = null, ?string $deviceId = null, ?string $status = null, ?int $limit = null): array
    {
        return $this->allAsync($labels, $deviceId, $status, $limit)->wait();
    }

    /**
     * @param array<string, string>|null $labels
     *
     * @return PromiseInterface resolves with list<Key>
     */
    public function allAsync(?array $labels = null, ?string $deviceId = null, ?string $status = null, ?int $limit = null): PromiseInterface
    {
        // Checked once, before the first page.
        LocalChecks::keyList($labels, $deviceId, $status, $limit);
        $keys = [];
        $next = function (?string $cursor) use (&$next, &$keys, $labels, $deviceId, $status, $limit): PromiseInterface {
            return $this->listAsync($labels, $deviceId, $status, $limit, $cursor)->then(
                static function (KeyPage $page) use (&$next, &$keys): mixed {
                    array_push($keys, ...$page->items);

                    return $page->nextCursor === null ? $keys : $next($page->nextCursor);
                },
            );
        };

        return $next(null);
    }

    /**
     * `PATCH /v1/keys/{id}`: changes a key, sending only the fields given,
     * at least one ("Nothing to change." otherwise, before anything is
     * sent). `labels` replaces them all; `labels`, `limits`,
     * `dailySpendCapUsd` and `expiresAt` given as null clear them. They are
     * checked as `create` checks them. A key's kind and `device_id` never
     * change. An expired key takes only a rename (else a 409 `key_expired`);
     * a revoked key none (409 `already_revoked`). A key the caller does not
     * manage is a NotFoundError.
     *
     * `labels` is a map of name => value, `limits` a map of
     * `requests_per_second` and `tokens_per_second`, `dailySpendCapUsd` a
     * number of USD and `expiresAt` an RFC 3339 time; each left out stays as
     * it is, and null clears it.
     *
     * @throws \InvalidArgumentException|ValidationError|BadRequestError|DecisionNodeError
     */
    public function update(
        string $keyId,
        ?string $name = null,
        mixed $labels = self::UNSET,
        mixed $limits = self::UNSET,
        mixed $dailySpendCapUsd = self::UNSET,
        mixed $expiresAt = self::UNSET,
        ?bool $maySearchCollections = null,
    ): Key {
        return $this->updateAsync($keyId, $name, $labels, $limits, $dailySpendCapUsd, $expiresAt, $maySearchCollections)->wait();
    }

    /**
     * @return PromiseInterface resolves with a Key
     */
    public function updateAsync(
        string $keyId,
        ?string $name = null,
        mixed $labels = self::UNSET,
        mixed $limits = self::UNSET,
        mixed $dailySpendCapUsd = self::UNSET,
        mixed $expiresAt = self::UNSET,
        ?bool $maySearchCollections = null,
    ): PromiseInterface {
        if ($expiresAt !== self::UNSET && $expiresAt !== null && !is_string($expiresAt)) {
            throw new \InvalidArgumentException('expiresAt is an RFC 3339 time, or null to clear it');
        }
        $fields = array_filter(
            ['labels' => $labels, 'limits' => $limits, 'daily_spend_cap' => $dailySpendCapUsd],
            static fn (mixed $value): bool => $value !== self::UNSET,
        );
        LocalChecks::keyFields($name !== null, $name, $fields, true);
        $body = [];
        if ($name !== null) {
            $body['name'] = $name;
        }
        foreach (['labels' => $labels, 'limits' => $limits] as $field => $value) {
            if ($value !== self::UNSET) {
                $body[$field] = is_array($value) ? self::map($value) : $value;
            }
        }
        if ($dailySpendCapUsd !== self::UNSET) {
            $body['daily_spend_cap'] = $dailySpendCapUsd === null ? null : ['usd' => $dailySpendCapUsd];
        }
        if ($expiresAt !== self::UNSET) {
            $body['expires_at'] = $expiresAt;
        }
        if ($maySearchCollections !== null) {
            $body['may_search_collections'] = $maySearchCollections;
        }
        if ($body === []) {
            throw new BadRequestError('Nothing to change.', errorType: 'api_usage_error', reason: 'Nothing to change.');
        }

        return $this->request('PATCH', self::path($keyId), Json::encode($body))->then(
            static fn (array $answer): Key => Key::fromArray($answer),
        );
    }

    /**
     * `DELETE /v1/keys/{id}`: revokes the key at once; it cannot be undone.
     * A key already revoked is a 409 `already_revoked`.
     *
     * @throws DecisionNodeError
     */
    public function revoke(string $keyId): RevokedKey
    {
        return $this->revokeAsync($keyId)->wait();
    }

    /**
     * @return PromiseInterface resolves with a RevokedKey
     */
    public function revokeAsync(string $keyId): PromiseInterface
    {
        return $this->request('DELETE', self::path($keyId))->then(static fn (array $answer): RevokedKey => RevokedKey::fromArray($answer));
    }

    /**
     * `POST /v1/keys/revoke`: revokes every active key whose labels match all
     * of `labels` (at least one), and, given `deviceIds` (up to 1,000), only
     * those among them; at most 10,000 a call. Call it again while `more` is
     * true. With `dryRun` true it revokes nothing and answers `wouldRevoke`;
     * `dryRun` is sent only when given.
     *
     * @param array<string, string>         $labels
     * @param array<array-key, string>|null $deviceIds
     *
     * @throws ValidationError|DecisionNodeError
     */
    public function revokeByLabels(array $labels, ?array $deviceIds = null, ?bool $dryRun = null): KeysRevoked
    {
        return $this->revokeByLabelsAsync($labels, $deviceIds, $dryRun)->wait();
    }

    /**
     * @param array<string, string>         $labels
     * @param array<array-key, string>|null $deviceIds
     *
     * @return PromiseInterface resolves with a KeysRevoked
     */
    public function revokeByLabelsAsync(array $labels, ?array $deviceIds = null, ?bool $dryRun = null): PromiseInterface
    {
        LocalChecks::keyRevoke($labels, $deviceIds);
        $body = ['labels' => self::map($labels)];
        if ($deviceIds !== null) {
            $body['device_ids'] = array_values($deviceIds);
        }
        if ($dryRun !== null) {
            $body['dry_run'] = $dryRun;
        }

        return $this->request('POST', '/v1/keys/revoke', Json::encode($body))->then(
            static fn (array $answer): KeysRevoked => KeysRevoked::fromArray($answer),
        );
    }

    /**
     * A map as the API takes it: always a JSON object, so an empty one is
     * sent as {}.
     *
     * @param array<array-key, mixed> $map
     *
     * @return \stdClass
     */
    private static function map(array $map): \stdClass
    {
        return (object) $map;
    }

    private static function path(string $keyId): string
    {
        return '/v1/keys/' . rawurlencode($keyId);
    }

    /**
     * @return PromiseInterface resolves with the response body, a JSON object
     */
    private function request(string $method, string $path, ?string $body = null, string $policy = Api::RETRY_DEFAULT, ?string $idempotencyKey = null): PromiseInterface
    {
        return $this->api->request($method, $path, $body, $policy, idempotencyKey: $idempotencyKey)->then(static function (array $result): array {
            if (!is_array($result['body'])) {
                /** @var array<string, string> $headers */
                $headers = $result['headers'];

                throw new ServerError(
                    sprintf('HTTP %d: the response body is not a JSON object.', (int) $result['status']),
                    status: (int) $result['status'],
                    requestId: $headers['x-request-id'] ?? null,
                    body: $result['body'],
                    headers: $headers,
                );
            }

            return $result['body'];
        });
    }
}
