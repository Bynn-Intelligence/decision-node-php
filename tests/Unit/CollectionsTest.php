<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\Collection;
use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\NotFoundError;
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

        $list = $dn->collections->list();
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
        // Create is not retried on a 500: one request, then the error.
        try {
            $dn->collections->create('Staff');
            self::fail('500');
        } catch (ServerError $e) {
            self::assertSame(500, $e->status);
        }
        self::assertCount(3, $this->history);
        $this->expectException(ServerError::class);
        $dn->collections->list();
    }
}
