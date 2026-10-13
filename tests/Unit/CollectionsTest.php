<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\Collection;
use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\NotFoundError;
use Bynn\DecisionNode\Exception\OverloadedError;
use Bynn\DecisionNode\Exception\PermissionDeniedError;
use Bynn\DecisionNode\Exception\ServerError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\Image;

final class CollectionsTest extends TestCase
{
    private const COL = 'col_3f6c1a9e0b2d4c7f8a1e5d9b2c4f6a80';

    public function testCallsOnTheWire(): void
    {
        $dn = $this->client([
            self::json(201, ['id' => self::COL, 'type' => 'face', 'name' => 'Staff', 'public' => false, 'items' => 0, 'created_at' => '2026-10-12T09:00:00Z', 'deletion' => null]),
            self::json(200, ['collections' => [
                ['id' => self::COL, 'type' => 'face', 'name' => 'Staff', 'public' => false, 'items' => 1, 'deletion' => ['at' => '2026-11-01T00:00:00Z', 'reason' => 'storage_unpaid']],
                ['id' => 'celebrities', 'type' => 'face', 'name' => 'celebrities', 'public' => true, 'items' => 1200],
            ]]),
            self::json(201, ['id' => 'itm_1', 'collection' => self::COL, 'reference' => 'emp-1042', 'label' => null, 'faces_found' => 2,
                'box' => ['x1' => 412, 'y1' => 220, 'x2' => 580, 'y2' => 470], 'created_at' => '2026-10-12T09:00:00Z', 'usage' => ['enrollments' => 1, 'cost' => ['usd' => 0.001]]]),
            self::json(200, ['items' => [['id' => 'itm_1', 'reference' => 'emp-1042', 'label' => 'Reception', 'created_at' => '2026-10-12T09:00:00Z']], 'offset' => 20, 'limit' => 5, 'total' => 21, 'next_offset' => null]),
            self::json(200, ['id' => 'itm/1', 'deleted' => true]),
            self::json(200, ['id' => self::COL, 'deleted' => true]),
        ]);

        $created = $dn->collections->create('Staff');
        self::assertSame([self::COL, false, 0, null], [$created->id, $created->public, $created->items, $created->deletion]);
        self::assertSame(['POST', '/v1/collections', '{"type":"face","name":"Staff"}'], [$this->sent(0)->getMethod(), $this->sent(0)->getUri()->getPath(), (string) $this->sent(0)->getBody()]);

        $uuid4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
        self::assertMatchesRegularExpression($uuid4, $this->sent(0)->getHeaderLine('Idempotency-Key'));

        $list = $dn->collections->list();
        self::assertSame('', $this->sent(1)->getHeaderLine('Idempotency-Key'));
        self::assertCount(2, $list);
        self::assertContainsOnlyInstancesOf(Collection::class, $list);
        self::assertSame(['storage_unpaid', true], [$list[0]->deletion['reason'] ?? null, $list[1]->public]);

        $item = $dn->collections->enroll(self::COL, Image::fromUrl('badge', 'https://files.example.com/b.jpg'), reference: 'emp-1042');
        self::assertSame(['itm_1', null, 2, 580, 1], [$item->id, $item->label, $item->facesFound, $item->box['x2'] ?? null, $item->usage['enrollments'] ?? null]);
        self::assertSame('{"image":{"id":"badge","url":"https://files.example.com/b.jpg"},"reference":"emp-1042"}', (string) $this->sent(2)->getBody());

        $page = $dn->collections->listItems(self::COL, offset: 20, limit: 5);
        self::assertSame(['Reception', 21, null], [$page->items[0]->label, $page->total, $page->nextOffset]);
        self::assertSame('offset=20&limit=5', $this->sent(3)->getUri()->getQuery());

        self::assertTrue($dn->collections->deleteItem(self::COL, 'itm/1')->deleted);
        self::assertSame('/v1/collections/' . self::COL . '/items/itm%2F1', $this->sent(4)->getUri()->getPath());
        self::assertTrue($dn->collections->delete(self::COL)->deleted);
        self::assertSame(['DELETE', '/v1/collections/' . self::COL], [$this->sent(5)->getMethod(), $this->sent(5)->getUri()->getPath()]);
    }

    public function testCheckedBeforeSending(): void
    {
        $dn = $this->client([]);
        $badge = Image::fromUrl('badge', 'https://files.example.com/b.jpg');
        $cases = [
            'string_too_short' => static fn () => $dn->collections->create(''),
            'string_too_long' => static fn () => $dn->collections->create(str_repeat('é', 81)),
            'literal_error' => static fn () => $dn->collections->create('Plates', 'plate'),
            'string_pattern_mismatch' => static fn () => $dn->collections->enroll(self::COL, $badge, reference: "emp-1042\n"),
            'missing' => static fn () => $dn->collections->enroll(self::COL, null, reference: 'emp-1042'),
        ];
        foreach ($cases as $type => $call) {
            try {
                $call();
                self::fail($type);
            } catch (ValidationError $e) {
                self::assertSame($type, $e->entries[0]['type'] ?? null);
            }
        }
        try {
            $dn->collections->enroll(self::COL, $badge, reference: 'r', label: str_repeat('l', 129));
            self::fail('label');
        } catch (ValidationError $e) {
            self::assertSame([['body', 'label'], 128], [$e->entries[0]['loc'] ?? null, $e->entries[0]['ctx']['max_length'] ?? null]);
        }
        try {
            $dn->collections->listItems(self::COL, limit: 101);
            self::fail('limit');
        } catch (BadRequestError $e) {
            self::assertSame('Query parameter limit must be an integer from 1 to 100, got 101.', $e->getMessage());
        }
        self::assertSame([], $this->history);
    }

    public function testErrorsReadAsSent(): void
    {
        $dn = $this->client([
            self::json(404, ['detail' => ['error_type' => 'collection_not_found', 'message' => 'Collection not found: x.']]),
            self::json(409, ['detail' => ['error_type' => 'collections_limit_reached', 'message' => 'This workspace has reached its limit of 100 collections.']]),
            self::json(500, ['detail' => 'boom']),
            self::json(500, ['detail' => 'boom']),
            self::json(500, ['detail' => 'boom']),
            self::json(200, ['collections' => 'none']),
        ]);
        try {
            $dn->collections->get('x');
            self::fail('404');
        } catch (NotFoundError $e) {
            self::assertSame('collection_not_found', $e->errorType);
        }
        try {
            $dn->collections->create('Staff');
            self::fail('409');
        } catch (DecisionNodeError $e) {
            self::assertSame([409, 'collections_limit_reached', DecisionNodeError::class], [$e->status, $e->errorType, get_class($e)]);
        }
        // Create carries its key, so a 500 is retried like any call; after
        // the retries, the error.
        try {
            $dn->collections->create('Staff');
            self::fail('500');
        } catch (ServerError $e) {
            self::assertSame(500, $e->status);
        }
        self::assertCount(5, $this->history);
        $this->expectException(ServerError::class);
        $dn->collections->list();
    }

    public function testCreateRetriedWithItsKey(): void
    {
        $dn = $this->client([
            self::json(500, ['detail' => ['error_type' => 'api_error', 'message' => 'Something went wrong on our side.']]),
            self::json(409, ['detail' => ['error_type' => 'idempotency_in_progress', 'message' => 'A request with this Idempotency-Key is still running.']], ['retry-after-ms' => '5']),
            self::json(201, ['id' => self::COL, 'type' => 'face', 'name' => 'Staff', 'public' => false, 'items' => 0]),
            self::json(409, ['detail' => ['error_type' => 'idempotency_key_reused', 'message' => 'This Idempotency-Key was already used with a different request.']]),
        ], ['maxRetries' => 3]);
        $dn->collections->create('Staff');
        self::assertCount(3, $this->history);
        $key = $this->sent(0)->getHeaderLine('Idempotency-Key');
        self::assertSame([$key, $key], [$this->sent(1)->getHeaderLine('Idempotency-Key'), $this->sent(2)->getHeaderLine('Idempotency-Key')]);
        try {
            $dn->collections->create('Staff');
            self::fail('no error');
        } catch (DecisionNodeError $e) {
            self::assertSame([409, 'idempotency_key_reused'], [$e->status, $e->errorType]);
        }
        self::assertCount(4, $this->history);
        self::assertNotSame($key, $this->sent(3)->getHeaderLine('Idempotency-Key'));
    }

    public function testEntriesEchoedAsTheApiEchoesThem(): void
    {
        $dn = $this->client([]);
        $badge = Image::fromUrl('badge', 'https://files.example.com/b.jpg');
        $entry = function (callable $call): array {
            try {
                $call();
                self::fail('no error');
            } catch (ValidationError $e) {
                return $e->entries[0];
            }
        };
        $e = $entry(fn () => $dn->collections->enroll(self::COL, $badge, reference: 'r', label: ''));
        self::assertSame(['string_too_short', ''], [$e['type'], $e['input']]);
        $e = $entry(fn () => $dn->collections->create(str_repeat('é', 81)));
        self::assertSame(['string_too_long', str_repeat('é', 64) . '...'], [$e['type'], $e['input']]);
        $e = $entry(fn () => $dn->collections->enroll(self::COL, $badge, reference: ''));
        self::assertSame('string_too_short', $e['type']);
        $e = $entry(fn () => $dn->collections->enroll(self::COL, null, reference: 'r'));
        self::assertSame(['missing', null], [$e['type'], $e['input']]);
        self::assertSame([], $this->history);
    }

    public function testUpdateItemSendsOnlyTheFieldsGiven(): void
    {
        $item = ['id' => 'itm_1', 'reference' => 'emp-1042', 'label' => null, 'metadata' => ['department' => 'Reception'], 'created_at' => '2026-10-12T09:00:00Z'];
        $dn = $this->client([self::json(200, $item), self::json(200, $item), self::json(200, $item), self::json(200, $item)]);

        $updated = $dn->collections->updateItem(self::COL, 'itm/1', reference: 'emp-1042', metadata: ['department' => 'Reception', 'badge_level' => 2]);
        self::assertSame(['emp-1042', ['department' => 'Reception']], [$updated->reference, $updated->metadata]);
        self::assertSame(['PATCH', '/v1/collections/' . self::COL . '/items/itm%2F1'], [$this->sent(0)->getMethod(), $this->sent(0)->getUri()->getPath()]);
        self::assertSame('{"reference":"emp-1042","metadata":{"department":"Reception","badge_level":2}}', (string) $this->sent(0)->getBody());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->sent(0)->getHeaderLine('Idempotency-Key'));

        // null clears the label; an unset label is not sent at all.
        $dn->collections->updateItem(self::COL, 'itm_1', label: null);
        self::assertSame('{"label":null}', (string) $this->sent(1)->getBody());
        $dn->collections->updateItem(self::COL, 'itm_1', label: 'Front desk');
        self::assertSame('{"label":"Front desk"}', (string) $this->sent(2)->getBody());
        // Metadata replaces the whole object: [] is sent as {}, which clears it.
        $dn->collections->updateItemAsync(self::COL, 'itm_1', metadata: [])->wait();
        self::assertSame('{"metadata":{}}', (string) $this->sent(3)->getBody());
    }

    public function testMetadataCheckedBeforeSending(): void
    {
        $dn = $this->client([]);
        $badge = Image::fromUrl('badge', 'https://files.example.com/b.jpg');
        $entries = function (callable $call): array {
            try {
                $call();
                self::fail('no error');
            } catch (ValidationError $e) {
                return $e->entries;
            }
        };

        $e = $entries(fn () => $dn->collections->updateItem(self::COL, 'itm_1'));
        self::assertSame([['type' => 'missing', 'loc' => ['body'], 'msg' => 'Send reference, label, metadata or any of them', 'input' => null]], $e);

        $many = [];
        for ($i = 0; $i < 21; ++$i) {
            $many['k' . $i] = $i;
        }
        $e = $entries(fn () => $dn->collections->enroll(self::COL, $badge, reference: 'r', metadata: $many));
        self::assertSame([['type' => 'value_error', 'loc' => ['body', 'metadata'], 'msg' => 'Metadata holds at most 20 keys.', 'input' => null]], $e);

        $e = $entries(fn () => $dn->collections->updateItem(self::COL, 'itm_1', metadata: ['Bad Key' => 1, 'note' => str_repeat('n', 501), 'tags' => ['a'], 'ok' => true]));
        self::assertSame([
            ['type' => 'value_error', 'loc' => ['body', 'metadata'], 'msg' => 'A metadata key is 1 to 40 characters of a-z, 0-9 and _: "Bad Key".', 'input' => null],
            ['type' => 'value_error', 'loc' => ['body', 'metadata', 'note'], 'msg' => 'A metadata text is at most 500 characters.'],
            ['type' => 'value_error', 'loc' => ['body', 'metadata', 'tags'], 'msg' => 'A metadata value is a text, a number or true or false.', 'input' => null],
        ], array_map(static fn (array $x): array => array_diff_key($x, $x['loc'] === ['body', 'metadata', 'note'] ? ['input' => 1] : []), $e));

        $e = $entries(fn () => $dn->collections->enroll(self::COL, $badge, reference: 'r', metadata: [str_repeat('k', 41) . "\x01" => 1]));
        self::assertSame('A metadata key is 1 to 40 characters of a-z, 0-9 and _: "' . str_repeat('k', 40) . '...".', $e[0]['msg']);
        self::assertSame([], $this->history);
    }

    public function testListItemsByReference(): void
    {
        $dn = $this->client([
            self::json(200, ['items' => [], 'offset' => 0, 'limit' => 20, 'total' => 10000, 'total_capped' => true, 'next_offset' => null, 'reference' => null, 'reference_prefix' => 'emp:']),
        ]);
        $page = $dn->collections->listItems(self::COL, referencePrefix: 'emp:');
        self::assertSame('reference_prefix=emp%3A', $this->sent(0)->getUri()->getQuery());
        self::assertSame([true, null, 'emp:'], [$page->totalCapped, $page->reference, $page->referencePrefix]);

        $message = function (callable $call): string {
            try {
                $call();
                self::fail('no error');
            } catch (BadRequestError $e) {
                self::assertSame('api_usage_error', $e->errorType);

                return $e->getMessage();
            }
        };
        self::assertSame(
            'Send query parameter reference or reference_prefix, not both.',
            $message(fn () => $dn->collections->listItems(self::COL, reference: 'a', referencePrefix: 'b')),
        );
        // The limit is checked before the lookup.
        self::assertStringStartsWith('Query parameter limit', $message(fn () => $dn->collections->listItems(self::COL, limit: 0, reference: 'a', referencePrefix: 'b')));
        self::assertSame(
            'Query parameter reference must be 1 to 64 characters of A-Z, a-z, 0-9, _, ., : and -, got emp 1\u{a}\u{e9}.',
            $message(fn () => $dn->collections->listItems(self::COL, reference: "emp 1\né")),
        );
        self::assertSame(
            'Query parameter reference_prefix must be 1 to 64 characters of A-Z, a-z, 0-9, _, ., : and -, got .',
            $message(fn () => $dn->collections->listItems(self::COL, referencePrefix: '')),
        );
        self::assertSame(
            'Query parameter reference must be 1 to 64 characters of A-Z, a-z, 0-9, _, ., : and -, got ' . str_repeat('x', 63) . ' ....',
            $message(fn () => $dn->collections->listItems(self::COL, reference: str_repeat('x', 63) . '  ')),
        );
        self::assertCount(1, $this->history);
    }

    public function testUnpricedEnrollmentNotRetried(): void
    {
        $unpriced = ['detail' => ['error_type' => 'overloaded_error', 'message' => 'Face enrollment is not available on this deployment yet.', 'details' => ['reason' => 'enrollment_unpriced']]];
        $dn = $this->client([
            self::json(529, $unpriced),
            self::json(403, ['detail' => ['error_type' => 'key_scope_denied', 'message' => 'This key may not use this endpoint.']]),
        ], ['maxRetries' => 3]);
        try {
            $dn->collections->enroll(self::COL, Image::fromUrl('badge', 'https://files.example.com/b.jpg'), reference: 'emp-1042');
            self::fail('no error');
        } catch (OverloadedError $e) {
            self::assertSame([529, ['reason' => 'enrollment_unpriced'], null], [$e->status, $e->details, $e->retryAfter]);
        }
        self::assertCount(1, $this->history);
        try {
            $dn->collections->list();
            self::fail('no error');
        } catch (PermissionDeniedError $e) {
            self::assertSame(['key_scope_denied', null], [$e->errorType, $e->details]);
        }
    }
}
