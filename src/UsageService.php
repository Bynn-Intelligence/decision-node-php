<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\ServerError;
use Bynn\DecisionNode\Internal\Api;
use Bynn\DecisionNode\Internal\LocalChecks;
use Bynn\DecisionNode\Internal\Query;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * Per-key usage: what each key spent and called over a period, for a Full
 * access key or a Manage device keys key. Reach it as `$dn->usage`.
 *
 *     $page = $dn->usage->list('2026-09-01T00:00:00Z', '2026-10-01T00:00:00Z', labels: ['customer' => 'acme']);
 *     foreach ($dn->usage->all('2026-09-01T00:00:00Z', '2026-10-01T00:00:00Z') as $item) {
 *         echo $item->key['name'], ' ', $item->totals['cost']['usd'], PHP_EOL;
 *     }
 *
 * `start` and `end` are RFC 3339 times, read in UTC and rounded to the hour.
 * The rules the API checks as it reads the query (`granularity`, `groupBy`,
 * the label filters, `deviceId`, the key ids, `scope`, `status`, `limit`)
 * are checked before anything is sent, as the API's `BadRequestError`
 * (`api_usage_error`) with its message; the period's own rules and the
 * cursor are the server's. Every result holds the API's fields and `raw`.
 * Reads are retried like every API call.
 */
final class UsageService
{
    /**
     * @internal
     */
    public function __construct(private readonly Api $api)
    {
    }

    /**
     * `GET /v1/keys/{key_id}/usage`: one key's usage. Left out, the period
     * is the UTC month so far. `granularity` is `none`, `hour`, `day` or
     * `month`; `groupBy` is `model` or `channel`. A key the caller may not
     * read, or no key with that id, is a NotFoundError ("Not found.").
     *
     * @throws BadRequestError|DecisionNodeError
     */
    public function key(string $keyId, ?string $start = null, ?string $end = null, ?string $granularity = null, ?string $groupBy = null): KeyUsage
    {
        return $this->keyAsync($keyId, $start, $end, $granularity, $groupBy)->wait();
    }

    /**
     * @return PromiseInterface resolves with a KeyUsage
     */
    public function keyAsync(string $keyId, ?string $start = null, ?string $end = null, ?string $granularity = null, ?string $groupBy = null): PromiseInterface
    {
        LocalChecks::usagePeriod($granularity, $groupBy);
        $path = '/v1/keys/' . rawurlencode($keyId) . '/usage' . Query::encode(self::period($start, $end, $granularity, $groupBy));

        return $this->get($path)->then(static fn (array $body): KeyUsage => KeyUsage::fromArray($body));
    }

    /**
     * `GET /v1/usage`: one page of the usage of every key the caller may
     * read over the period (a Manage device keys key reads the
     * Decisions-only keys), revoked and expired keys included. `labels`
     * (name => value) must all match, sent as `label=name:value` by name;
     * each key id is sent as its own `key_id`, at most 100; `scope` is
     * `full`, `decide` or `keys`; `status` `active`, `expired`, `revoked` or
     * `all`; `includeEmpty` lists the keys with no usage too; `limit` is 1 to
     * 1,000; `cursor` is the `nextCursor` of the page before. Each is sent
     * only when given.
     *
     * @param array<string, string>|null $labels
     * @param list<string>|null          $keyIds
     *
     * @throws BadRequestError|DecisionNodeError
     */
    public function list(
        string $start,
        string $end,
        ?string $granularity = null,
        ?string $groupBy = null,
        ?array $labels = null,
        ?string $deviceId = null,
        ?array $keyIds = null,
        ?string $scope = null,
        ?string $status = null,
        ?bool $includeEmpty = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): UsagePage {
        return $this->listAsync($start, $end, $granularity, $groupBy, $labels, $deviceId, $keyIds, $scope, $status, $includeEmpty, $limit, $cursor)->wait();
    }

    /**
     * @param array<string, string>|null $labels
     * @param list<string>|null          $keyIds
     *
     * @return PromiseInterface resolves with a UsagePage
     */
    public function listAsync(
        string $start,
        string $end,
        ?string $granularity = null,
        ?string $groupBy = null,
        ?array $labels = null,
        ?string $deviceId = null,
        ?array $keyIds = null,
        ?string $scope = null,
        ?string $status = null,
        ?bool $includeEmpty = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): PromiseInterface {
        $path = '/v1/usage' . Query::encode(self::listQuery($start, $end, $granularity, $groupBy, $labels, $deviceId, $keyIds, $scope, $status, $includeEmpty, $limit, $cursor));

        return $this->get($path)->then(static fn (array $body): UsagePage => UsagePage::fromArray($body));
    }

    /**
     * Every key's usage over the period: the pages of `list()`, `nextCursor`
     * followed until it is null, their items in order. A page can hold fewer
     * keys than `limit`, so only a null cursor ends the read.
     *
     * @param array<string, string>|null $labels
     * @param list<string>|null          $keyIds
     *
     * @return list<UsageItem>
     *
     * @throws BadRequestError|DecisionNodeError
     */
    public function all(
        string $start,
        string $end,
        ?string $granularity = null,
        ?string $groupBy = null,
        ?array $labels = null,
        ?string $deviceId = null,
        ?array $keyIds = null,
        ?string $scope = null,
        ?string $status = null,
        ?bool $includeEmpty = null,
        ?int $limit = null,
    ): array {
        return $this->allAsync($start, $end, $granularity, $groupBy, $labels, $deviceId, $keyIds, $scope, $status, $includeEmpty, $limit)->wait();
    }

    /**
     * @param array<string, string>|null $labels
     * @param list<string>|null          $keyIds
     *
     * @return PromiseInterface resolves with list<UsageItem>
     */
    public function allAsync(
        string $start,
        string $end,
        ?string $granularity = null,
        ?string $groupBy = null,
        ?array $labels = null,
        ?string $deviceId = null,
        ?array $keyIds = null,
        ?string $scope = null,
        ?string $status = null,
        ?bool $includeEmpty = null,
        ?int $limit = null,
    ): PromiseInterface {
        // Checked once, before the first page.
        self::listQuery($start, $end, $granularity, $groupBy, $labels, $deviceId, $keyIds, $scope, $status, $includeEmpty, $limit, null);
        $items = [];
        $next = function (?string $cursor) use (&$next, &$items, $start, $end, $granularity, $groupBy, $labels, $deviceId, $keyIds, $scope, $status, $includeEmpty, $limit): PromiseInterface {
            return $this->listAsync($start, $end, $granularity, $groupBy, $labels, $deviceId, $keyIds, $scope, $status, $includeEmpty, $limit, $cursor)->then(
                static function (UsagePage $page) use (&$next, &$items): mixed {
                    array_push($items, ...$page->items);

                    return $page->nextCursor === null ? $items : $next($page->nextCursor);
                },
            );
        };

        return $next(null);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function period(?string $start, ?string $end, ?string $granularity, ?string $groupBy): array
    {
        $pairs = [];
        foreach (['start' => $start, 'end' => $end, 'granularity' => $granularity, 'group_by' => $groupBy] as $name => $value) {
            if ($value !== null) {
                $pairs[] = [$name, $value];
            }
        }

        return $pairs;
    }

    /**
     * The checked query of a usage page, in the order the API reads it.
     *
     * @param array<array-key, mixed>|null $labels
     * @param list<mixed>|null             $keyIds
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function listQuery(
        string $start,
        string $end,
        ?string $granularity,
        ?string $groupBy,
        ?array $labels,
        ?string $deviceId,
        ?array $keyIds,
        ?string $scope,
        ?string $status,
        ?bool $includeEmpty,
        ?int $limit,
        ?string $cursor,
    ): array {
        LocalChecks::usagePeriod($granularity, $groupBy);
        LocalChecks::usageFilters($labels, $deviceId, $keyIds, $scope, $status, $limit);
        $pairs = array_merge(self::period($start, $end, $granularity, $groupBy), Query::labels($labels));
        if ($deviceId !== null) {
            $pairs[] = ['device_id', $deviceId];
        }
        foreach ($keyIds ?? [] as $keyId) {
            $pairs[] = ['key_id', (string) $keyId];
        }
        foreach (['scope' => $scope, 'status' => $status] as $name => $value) {
            if ($value !== null) {
                $pairs[] = [$name, $value];
            }
        }
        if ($includeEmpty !== null) {
            $pairs[] = ['include_empty', $includeEmpty ? 'true' : 'false'];
        }
        if ($limit !== null) {
            $pairs[] = ['limit', (string) $limit];
        }
        if ($cursor !== null) {
            $pairs[] = ['cursor', $cursor];
        }

        return $pairs;
    }

    /**
     * @return PromiseInterface resolves with the response body, a JSON object
     */
    private function get(string $path): PromiseInterface
    {
        return $this->api->request('GET', $path)->then(static function (array $result): array {
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
