<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\PermissionDeniedError;
use Bynn\DecisionNode\Exception\RateLimitError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\Key;
use Bynn\DecisionNode\UsageItem;

final class KeysAndUsageTest extends TestCase
{
    private const KEY_ID = 'key_7c2e9a41b0d34f6e8a5c1d2b3e4f5a60';

    private const PERIOD = ['start' => '2026-09-01T00:00:00Z', 'end' => '2026-10-01T00:00:00Z'];

    /** @return array<string, mixed> */
    private static function keyObject(string $id = self::KEY_ID): array
    {
        return [
            'id' => $id, 'name' => 'Kiosk 0412', 'prefix' => 'dn_live_', 'scope' => 'decide', 'last4' => '9f3a',
            'device_id' => 'kiosk-0412', 'labels' => ['store' => '118', 'fw' => '2.4'],
            'limits' => ['requests_per_second' => 5, 'tokens_per_second' => 20000],
            'daily_spend_cap' => ['usd' => 2.0], 'spent_today' => ['usd' => 0.0], 'expires_at' => '2027-10-13T00:00:00Z',
            'may_search_collections' => false, 'status' => 'active', 'created_by_key' => ['id' => 'key_1f0e2d3c4b5a69788796a5b4c3d2e1f0'],
            'created_at' => '2026-10-20T09:12:04Z', 'last_used_at' => null, 'revoked_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function usageItem(string $id): array
    {
        return ['key' => ['id' => $id, 'name' => $id, 'scope' => 'decide', 'device_id' => null, 'labels' => [], 'status' => 'active', 'created_at' => '2026-08-20T08:00:00Z', 'revoked_at' => null, 'expires_at' => null],
            'totals' => ['requests' => 1, 'free_tier_requests' => 0, 'input_tokens' => 10, 'image_tokens' => 0, 'video_tokens' => 0, 'booster_calls' => 0, 'cost' => ['usd' => 0.000001, 'model_usd' => 0.000001, 'boosters_usd' => 0.0]]];
    }

    public function testUsageQueries(): void
    {
        $dn = $this->client([
            self::json(200, ['key' => self::usageItem(self::KEY_ID)['key'], 'period' => self::PERIOD, 'as_of' => '2026-10-01T00:00:00Z', 'totals' => self::usageItem(self::KEY_ID)['totals'], 'buckets' => [['start' => '2026-09-03T00:00:00Z', 'end' => '2026-09-04T00:00:00Z', 'totals' => []]]]),
            self::json(200, ['period' => self::PERIOD, 'as_of' => '2026-10-01T00:00:00Z', 'items' => [self::usageItem('key_a')], 'next_cursor' => null]),
            self::json(200, []),
        ]);
        $usage = $dn->usage->key('key/a', '2026-09-01T00:00:00Z', '2026-10-01T00:00:00Z', 'day');
        self::assertSame(['/v1/keys/key%2Fa/usage', 'start=2026-09-01T00%3A00%3A00Z&end=2026-10-01T00%3A00%3A00Z&granularity=day'], [$this->sent(0)->getUri()->getPath(), $this->sent(0)->getUri()->getQuery()]);
        self::assertSame(['2026-10-01T00:00:00Z', 1, '2026-09-03T00:00:00Z', null], [$usage->asOf, $usage->totals['requests'] ?? null, $usage->buckets[0]['start'] ?? null, $usage->byModel]);

        $page = $dn->usage->list('2026-09-01T00:00:00Z', '2026-10-01T00:00:00Z', groupBy: 'channel', labels: ['plan' => 'pro', 'customer' => 'acme'], deviceId: 'drone 1',
            keyIds: ['key_00000000000000000000000000000d01', 'key_00000000000000000000000000000d02'], scope: 'decide', status: 'all', includeEmpty: false, limit: 50, cursor: 'c/2');
        self::assertSame('start=2026-09-01T00%3A00%3A00Z&end=2026-10-01T00%3A00%3A00Z&group_by=channel&label=customer%3Aacme&label=plan%3Apro&device_id=drone%201'
            . '&key_id=key_00000000000000000000000000000d01&key_id=key_00000000000000000000000000000d02&scope=decide&status=all&include_empty=false&limit=50&cursor=c%2F2',
            $this->sent(1)->getUri()->getQuery());
        self::assertSame(['key_a', null], [$page->items[0]->key['id'] ?? null, $page->nextCursor]);

        $dn->usage->key(self::KEY_ID);
        self::assertSame('', $this->sent(2)->getUri()->getQuery());
    }

    public function testUsageAllFollowsTheCursor(): void
    {
        $dn = $this->client([
            self::json(200, ['period' => self::PERIOD, 'items' => [self::usageItem('key_a')], 'next_cursor' => 'c2']),
            self::json(200, ['period' => self::PERIOD, 'items' => [], 'next_cursor' => 'c3']),
            self::json(200, ['period' => self::PERIOD, 'items' => [self::usageItem('key_b'), self::usageItem('key_c')], 'next_cursor' => null]),
        ]);
        $items = $dn->usage->all('2026-09-01T00:00:00Z', '2026-10-01T00:00:00Z', limit: 2);
        self::assertContainsOnlyInstancesOf(UsageItem::class, $items);
        self::assertSame(['key_a', 'key_b', 'key_c'], array_map(static fn (UsageItem $i): mixed => $i->key['id'] ?? null, $items));
        self::assertCount(3, $this->history);
        self::assertStringEndsWith('&limit=2&cursor=c3', $this->sent(2)->getUri()->getQuery());
        self::assertStringNotContainsString('cursor', $this->sent(0)->getUri()->getQuery());
    }

    public function testUsageCheckedBeforeSending(): void
    {
        $dn = $this->client([]);
        $start = '2026-09-01T00:00:00Z';
        $end = '2026-10-01T00:00:00Z';
        $cases = [
            'granularity is none, hour, day or month.' => static fn () => $dn->usage->key(self::KEY_ID, granularity: 'week'),
            'group_by is model or channel.' => static fn () => $dn->usage->list($start, $end, groupBy: 'device'),
            'A label filter is name:value, as labels are written.' => static fn () => $dn->usage->list($start, $end, labels: ['plan' => "pro\n"]),
            'device_id is 1 to 128 printable ASCII characters, not starting with a space.' => static fn () => $dn->usage->list($start, $end, deviceId: 'drone-é'),
            "key_id is a key's id, key_ and 32 hex digits." => static fn () => $dn->usage->all($start, $end, keyIds: ['key_ABC']),
            'At most 100 key_id filters.' => static fn () => $dn->usage->list($start, $end, keyIds: array_map(static fn (int $i): string => sprintf('key_%032x', $i), range(0, 100))),
            'scope is full, decide or keys.' => static fn () => $dn->usage->list($start, $end, scope: 'publishable'),
            'status is active, expired, revoked or all.' => static fn () => $dn->usage->list($start, $end, status: 'deleted'),
            'limit is a whole number from 1 to 1000.' => static fn () => $dn->usage->list($start, $end, limit: 1001),
        ];
        foreach ($cases as $message => $call) {
            try {
                $call();
                self::fail($message);
            } catch (BadRequestError $e) {
                self::assertSame([$message, 'api_usage_error', null], [$e->getMessage(), $e->errorType, $e->status]);
            }
        }
        self::assertCount(0, $this->history);
    }

    public function testCreateSendsItsIdempotencyKeyOnEveryAttempt(): void
    {
        $dn = $this->client([
            self::json(409, ['detail' => ['error_type' => 'idempotency_in_progress', 'message' => 'A request with this Idempotency-Key is still running. Try again shortly.']], ['Retry-After' => '1']),
            self::json(201, ['key' => self::keyObject(), 'secret' => 'dn_live_' . str_repeat('ab', 32)]),
            self::json(201, ['key' => self::keyObject(), 'secret' => 'dn_live_' . str_repeat('cd', 32)]),
        ]);
        $made = $dn->keys->create('Kiosk 0412', deviceId: 'kiosk-0412', labels: ['store' => '118'], limits: ['requests_per_second' => 5],
            dailySpendCapUsd: 2.0, expiresAt: '2027-10-13T00:00:00Z', maySearchCollections: false, idempotencyKey: 'enroll-kiosk-0412');
        self::assertSame([self::KEY_ID, 'dn_live_' . str_repeat('ab', 32), ['store' => '118', 'fw' => '2.4'], ['usd' => 2.0]], [$made->key->id, $made->secret, $made->key->labels, $made->key->dailySpendCap]);
        self::assertSame(['POST', '/v1/keys', 'enroll-kiosk-0412', 'enroll-kiosk-0412'], [$this->sent(1)->getMethod(), $this->sent(1)->getUri()->getPath(), $this->sent(0)->getHeaderLine('Idempotency-Key'), $this->sent(1)->getHeaderLine('Idempotency-Key')]);
        self::assertSame('{"name":"Kiosk 0412","device_id":"kiosk-0412","labels":{"store":"118"},"limits":{"requests_per_second":5},"daily_spend_cap":{"usd":2.0},'
            . '"expires_at":"2027-10-13T00:00:00Z","may_search_collections":false}', (string) $this->sent(1)->getBody());
        self::assertStringNotContainsString(str_repeat('ab', 32), print_r($made, true));

        $dn->keys->create('Kiosk 0413');
        self::assertSame('{"name":"Kiosk 0413"}', (string) $this->sent(2)->getBody());
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $this->sent(2)->getHeaderLine('Idempotency-Key'));
    }

    public function testKeyCalls(): void
    {
        $dn = $this->client([
            self::json(200, ['items' => [self::keyObject()], 'next_cursor' => 'n2']),
            self::json(200, self::keyObject()),
            self::json(200, self::keyObject()),
            self::json(200, ['id' => self::KEY_ID, 'revoked' => true]),
            self::json(200, ['would_revoke' => 37, 'more' => false]),
            self::json(200, ['revoked' => 35, 'more' => true]),
        ]);
        $page = $dn->keys->list(labels: ['store' => '118', 'fw' => '2.4'], status: 'all', limit: 50);
        self::assertSame(['GET', '/v1/keys', 'label=fw%3A2.4&label=store%3A118&status=all&limit=50', 'n2'], [$this->sent(0)->getMethod(), $this->sent(0)->getUri()->getPath(), $this->sent(0)->getUri()->getQuery(), $page->nextCursor]);
        self::assertInstanceOf(Key::class, $page->items[0]);
        self::assertSame(['decide', 'kiosk-0412', 5, false, 'key_1f0e2d3c4b5a69788796a5b4c3d2e1f0', null], [$page->items[0]->scope, $page->items[0]->deviceId, $page->items[0]->limits['requests_per_second'] ?? null, $page->items[0]->maySearchCollections, $page->items[0]->createdByKey['id'] ?? null, $page->items[0]->lastUsedAt]);

        $dn->keys->update(self::KEY_ID, name: 'Kiosk 12', labels: ['store' => '119'], dailySpendCapUsd: null, expiresAt: null);
        self::assertSame(['PATCH', '/v1/keys/' . self::KEY_ID, '{"name":"Kiosk 12","labels":{"store":"119"},"daily_spend_cap":null,"expires_at":null}', ''],
            [$this->sent(1)->getMethod(), $this->sent(1)->getUri()->getPath(), (string) $this->sent(1)->getBody(), $this->sent(1)->getHeaderLine('Idempotency-Key')]);
        $dn->keys->update(self::KEY_ID, labels: null, limits: ['tokens_per_second' => 100], dailySpendCapUsd: 3, maySearchCollections: true);
        self::assertSame('{"labels":null,"limits":{"tokens_per_second":100},"daily_spend_cap":{"usd":3},"may_search_collections":true}', (string) $this->sent(2)->getBody());

        $revoked = $dn->keys->revoke(self::KEY_ID);
        self::assertSame(['DELETE', '/v1/keys/' . self::KEY_ID, true], [$this->sent(3)->getMethod(), $this->sent(3)->getUri()->getPath(), $revoked->revoked]);

        $counted = $dn->keys->revokeByLabels(['store' => '118'], dryRun: true);
        self::assertSame([null, 37, false], [$counted->revoked, $counted->wouldRevoke, $counted->more]);
        self::assertSame(['POST', '/v1/keys/revoke', '{"labels":{"store":"118"},"dry_run":true}'], [$this->sent(4)->getMethod(), $this->sent(4)->getUri()->getPath(), (string) $this->sent(4)->getBody()]);
        $done = $dn->keys->revokeByLabels(['store' => '118'], deviceIds: ['kiosk-0412'], dryRun: false);
        self::assertSame([35, null, true], [$done->revoked, $done->wouldRevoke, $done->more]);
        self::assertSame('{"labels":{"store":"118"},"device_ids":["kiosk-0412"],"dry_run":false}', (string) $this->sent(5)->getBody());
    }

    public function testKeysAllFollowsTheCursor(): void
    {
        $dn = $this->client([
            self::json(200, ['items' => [self::keyObject('key_a')], 'next_cursor' => 'n2']),
            self::json(200, ['items' => [self::keyObject('key_b')], 'next_cursor' => null]),
        ]);
        self::assertSame(['key_a', 'key_b'], array_map(static fn (Key $k): ?string => $k->id, $dn->keys->all(deviceId: 'kiosk-0412')));
        self::assertSame('device_id=kiosk-0412&cursor=n2', $this->sent(1)->getUri()->getQuery());
    }

    public function testKeysCheckedBeforeSending(): void
    {
        $dn = $this->client([]);
        $refused = [
            ['body', 'name', 'Give the key a name.', '   ', static fn () => $dn->keys->create('   ')],
            ['body', 'name', 'Key names are at most 48 characters.', str_repeat('n', 49), static fn () => $dn->keys->create(str_repeat('n', 49))],
            ['body', 'device_id', 'device_id is 1 to 128 printable ASCII characters, not starting with a space.', ' gate', static fn () => $dn->keys->create('Gate', deviceId: ' gate')],
            ['body', 'labels', 'A key has at most 16 labels.', null, static fn () => $dn->keys->create('Gate', labels: array_combine(array_map(static fn (int $i): string => 'l' . $i, range(0, 16)), array_fill(0, 17, 'v')))],
            ['body', 'labels', "A label's name is 1 to 32 of a-z, 0-9, _ . and -.", null, static fn () => $dn->keys->create('Gate', labels: ['Site' => 'oslo'])],
            ['body', 'labels', 'site', "A label's value is 1 to 64 characters of text.", null, static fn () => $dn->keys->update(self::KEY_ID, labels: ['site' => ''])],
            ['body', 'labels', 'labels is an object of names to values.', 'site:oslo', static fn () => $dn->keys->update(self::KEY_ID, labels: 'site:oslo')],
            ['body', 'limits', 'limits is an object, or null.', null, static fn () => $dn->keys->create('Gate', limits: [5])],
            ['body', 'daily_spend_cap', 'daily_spend_cap.usd is from 0.01 to 1000, in cents, or null.', null, static fn () => $dn->keys->create('Gate', dailySpendCapUsd: 0.005)],
            ['body', 'daily_spend_cap', 'daily_spend_cap.usd is from 0.01 to 1000, in cents, or null.', null, static fn () => $dn->keys->update(self::KEY_ID, dailySpendCapUsd: 1000.01)],
            ['body', 'labels', 'A bulk revoke names at least one label: every key it revokes has them all.', null, static fn () => $dn->keys->revokeByLabels([])],
            ['body', 'device_ids', 'device_ids is a list of at most 1,000 device ids.', null, static fn () => $dn->keys->revokeByLabels(['site' => 'oslo'], deviceIds: array_fill(0, 1001, 'd'))],
            ['body', 'device_id', 'device_id is 1 to 128 printable ASCII characters, not starting with a space.', '', static fn () => $dn->keys->revokeByLabels(['site' => 'oslo'], deviceIds: ['d-1', ''])],
        ];
        foreach ($refused as $case) {
            $call = array_pop($case);
            $input = array_pop($case);
            $message = array_pop($case);
            try {
                $call();
                self::fail($message);
            } catch (ValidationError $e) {
                self::assertSame([['type' => 'value_error', 'loc' => $case, 'msg' => $message, 'input' => $input]], $e->entries);
            }
        }
        $bad = [
            'Idempotency-Key must be sent once, as 1 to 255 printable ASCII characters.' => static fn () => $dn->keys->create('Gate', idempotencyKey: ''),
            'Nothing to change.' => static fn () => $dn->keys->update(self::KEY_ID),
            'limit is a whole number from 1 to 200.' => static fn () => $dn->keys->list(limit: 201),
            'status is active, expired, revoked or all.' => static fn () => $dn->keys->all(status: 'gone'),
            'A label filter is name:value, as labels are written.' => static fn () => $dn->keys->list(labels: ['Site' => 'oslo']),
        ];
        foreach ($bad as $message => $call) {
            try {
                $call();
                self::fail($message);
            } catch (BadRequestError $e) {
                self::assertSame([$message, 'api_usage_error'], [$e->getMessage(), $e->errorType]);
            }
        }
        // Clearing is never refused, and a cleared field counts as a change.
        $dn = $this->client([self::json(200, self::keyObject())]);
        $dn->keys->update(self::KEY_ID, limits: null);
        self::assertSame('{"limits":null}', (string) $this->sent(0)->getBody());
    }

    public function testSpendCapNotRetried(): void
    {
        $cap = ['detail' => ['error_type' => 'key_spend_cap_reached', 'message' => "This key's daily spend cap of USD 2.00 is reached. It resets at 00:00 UTC.",
            'details' => ['resets_at' => '2026-10-21T00:00:00Z', 'cap' => ['usd' => 2.0], 'spent' => ['usd' => 2.01]]]];
        $dn = $this->client([self::json(429, $cap, ['Retry-After' => '30000'])], ['maxRetries' => 3]);
        try {
            $dn->check('?', 'Is it?');
            self::fail('no error');
        } catch (RateLimitError $e) {
            self::assertSame([429, 'key_spend_cap_reached', '2026-10-21T00:00:00Z'], [$e->status, $e->errorType, $e->details['resets_at'] ?? null]);
        }
        self::assertCount(1, $this->history);
    }

    public function testPublishableKeyRefusals(): void
    {
        $dn = $this->client([
            self::json(403, ['detail' => ['error_type' => 'model_not_allowed', 'message' => 'This key may not ask this model. Its models: decisionnode-flash.', 'details' => ['allowed' => ['decisionnode-flash']]]]),
            self::json(429, ['detail' => ['error_type' => 'rate_limit_error', 'message' => 'Rate limit reached for this address. Retry after 1 s.', 'details' => ['reason' => 'client_rate']]], ['retry-after-ms' => '1']),
            self::truthBody(0.94, 'check'),
        ], ['maxRetries' => 1]);
        try {
            $dn->check('?', 'Is it?', model: 'decisionnode-moe');
            self::fail('no error');
        } catch (PermissionDeniedError $e) {
            self::assertSame(['model_not_allowed', ['allowed' => ['decisionnode-flash']]], [$e->errorType, $e->details]);
        }
        self::assertSame(0.94, $dn->check('?', 'Is it?')->truth);
        self::assertCount(3, $this->history);
    }

    public function testKeysErrorsAreTheApis(): void
    {
        $dn = $this->client([self::json(403, ['detail' => ['error_type' => 'key_scope_denied', 'message' => 'Keys are managed with a keys key, made in the console.']])]);
        try {
            $dn->keys->list();
            self::fail('no error');
        } catch (DecisionNodeError $e) {
            self::assertInstanceOf(PermissionDeniedError::class, $e);
            self::assertSame('key_scope_denied', $e->errorType);
        }
    }
}
