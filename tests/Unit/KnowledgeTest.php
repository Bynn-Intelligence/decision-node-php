<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\Booster;
use Bynn\DecisionNode\Collection;
use Bynn\DecisionNode\CollectionItem;
use Bynn\DecisionNode\Document;
use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\OverloadedError;
use Bynn\DecisionNode\Exception\RateLimitError;
use Bynn\DecisionNode\Exception\ServerError;
use Bynn\DecisionNode\Exception\TimeoutError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\KnowledgeReading;
use Bynn\DecisionNode\UnknownReading;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

use function Bynn\DecisionNode\truth;

final class KnowledgeTest extends TestCase
{
    private const COL = 'col_3f6c1a9e0b2d4c7f8a1e5d9b2c4f6a80';

    private const ITEMS = '/v1/collections/' . self::COL . '/items';

    /**
     * @return array<string, mixed>
     */
    private static function item(string $status = 'queued', array $more = []): array
    {
        return $more + ['id' => 'itm_1', 'collection' => self::COL, 'reference' => 'policy-2026', 'label' => null, 'title' => 'Claims policy',
            'format' => 'markdown', 'pinned' => false, 'pin_rank' => 0, 'source' => 'article', 'file' => null, 'status' => $status,
            'stage' => null, 'progress' => null, 'serving' => 'none', 'error' => null, 'delayed' => false, 'chunks' => 0, 'tokens' => 0,
            'version' => 1, 'queued_at' => '2026-10-20T10:00:00Z', 'ready_at' => null, 'created_at' => '2026-10-20T10:00:00Z'];
    }

    /**
     * @param list<string> $ids
     */
    private static function presigned(array $ids): Response
    {
        return self::json(201, ['uploads' => array_map(static fn (string $id): array => [
            'upload_id' => $id,
            'purpose' => 'knowledge',
            'url' => 'https://storage.example.test/uploads/' . $id . '?X-Amz-Signature=abc',
            'method' => 'PUT',
            'headers' => ['Content-Type' => 'application/pdf', 'Content-MD5' => 'md5', 'If-None-Match' => '*'],
            'expires_at' => '2099-01-01T00:00:00Z',
        ], $ids), 'expires_in' => 300]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function reading(array $passage = []): array
    {
        return [
            'collection' => self::COL, 'collection_name' => 'Claims policy', 'corpus' => ['version' => 41],
            'query' => ['source' => 'auto', 'tokens' => 212], 'budget' => ['mode' => 'auto', 'tokens' => 840, 'entry' => 'short', 'cause' => null],
            'scope' => ['key' => 'mission', 'value' => 'M-42'], 'warnings' => [],
            'passages' => [$passage + ['n' => 1, 'pinned' => true, 'item' => 'itm_1', 'reference' => 'policy-2026', 'title' => 'Claims policy 2026',
                'heading' => 'Receipts', 'chunk' => 3, 'byte_start' => 1840, 'byte_end' => 2310, 'tokens' => 96, 'score' => null,
                'lexical' => false, 'rendered' => true]],
            'omitted' => ['over_budget' => 1], 'pending_items' => 0, 'search' => ['plan' => 'exact', 'candidates' => 50, 'fallback' => false],
            'truncated' => false,
        ];
    }

    private static function pdf(int $size = 2000): string
    {
        return '%PDF-1.7' . str_repeat('x', $size - 8);
    }

    public function testATextCollectionAndItsFields(): void
    {
        $dn = $this->client([
            self::json(201, ['id' => self::COL, 'type' => 'text', 'name' => 'Claims policy', 'public' => false, 'items' => 12,
                'created_at' => '2026-10-20T10:00:00Z', 'deletion' => null, 'scope_key' => 'mission', 'chunks' => 412, 'tokens' => 81230,
                'version' => 41, 'status_counts' => ['queued' => 1, 'processing' => 1, 'ready' => 9, 'failed' => 1],
                'pinned' => ['tokens' => 230, 'cap' => 512, 'largest_scope' => ['value' => 'M-42', 'tokens' => 96]]]),
            self::json(202, ['id' => self::COL, 'status' => 'purging']),
        ]);
        $kb = $dn->collections->create('Claims policy', type: 'text', scopeKey: 'mission');
        self::assertSame('{"type":"text","name":"Claims policy","scope_key":"mission"}', (string) $this->sent()->getBody());
        self::assertNotSame('', $this->sent()->getHeaderLine('Idempotency-Key'));
        self::assertSame(['mission', 412, 81230, 41, 9, 512], [$kb->scopeKey, $kb->chunks, $kb->tokens, $kb->version, $kb->statusCounts['ready'] ?? null, $kb->pinned['cap'] ?? null]);

        $deleted = $dn->collections->delete(self::COL);
        self::assertSame([false, 'purging', self::COL], [$deleted->deleted, $deleted->status, $deleted->id]);
    }

    public function testAFaceCollectionHasNoKnowledgeFields(): void
    {
        $dn = $this->client([self::json(201, ['id' => self::COL, 'type' => 'face', 'name' => 'Staff', 'public' => false, 'items' => 0])]);
        $c = $dn->collections->create('Staff');
        self::assertSame('{"type":"face","name":"Staff"}', (string) $this->sent()->getBody());
        self::assertSame([null, null, null, null], [$c->scopeKey, $c->chunks, $c->statusCounts, $c->pinned]);
    }

    public function testMarkdownIsSentInline(): void
    {
        $dn = $this->client([self::json(202, self::item())]);
        $item = $dn->collections->addDocument(self::COL, Document::fromText("# Receipts\n\nNo claim over 500 SEK without one."), title: 'Claims policy',
            reference: 'policy-2026', pinned: true, pinRank: 3, label: 'Rules', metadata: ['mission' => 'M-42']);
        self::assertSame(['POST', self::ITEMS, ''], [$this->sent()->getMethod(), $this->sent()->getUri()->getPath(), $this->sent()->getUri()->getQuery()]);
        self::assertSame('{"title":"Claims policy","text":"# Receipts\n\nNo claim over 500 SEK without one.","reference":"policy-2026","pinned":true,"pin_rank":3,"label":"Rules","metadata":{"mission":"M-42"}}', (string) $this->sent()->getBody());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->sent()->getHeaderLine('Idempotency-Key'));
        self::assertSame(['queued', 'Claims policy', 'article', [], false], [$item->status, $item->title, $item->source, $item->warnings, $item->settled()]);
    }

    public function testWaitAsksTheServerToHoldTheCall(): void
    {
        $dn = $this->client([self::json(201, self::item('ready'))]);
        $item = $dn->collections->addDocument(self::COL, Document::fromText('Plain words.', Document::TEXT), title: 'Note', wait: true);
        self::assertSame('wait=true', $this->sent()->getUri()->getQuery());
        self::assertSame('{"title":"Note","text":"Plain words.","format":"text"}', (string) $this->sent()->getBody());
        self::assertSame(20.0, $this->history[0]['options']['timeout'] ?? null);
        self::assertTrue($item->settled());

        $dn = $this->client([self::json(201, self::item('ready'))], ['timeout' => 4.0]);
        $dn->collections->addDocument(self::COL, Document::fromText('x'), title: 'Note', wait: true);
        self::assertSame(4.0, $this->history[0]['options']['timeout'] ?? null);
    }

    public function testASmallMarkdownFileIsSentInlineTitledByItsStem(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dn') . '.house-rules.md';
        file_put_contents($path, "# Rules\n\nNo smoking.");
        try {
            $dn = $this->client([self::json(202, self::item())]);
            $dn->collections->addDocument(self::COL, Document::fromPath($path));
            $body = $this->sentBody();
            self::assertSame([pathinfo($path, PATHINFO_FILENAME), "# Rules\n\nNo smoking.", 'markdown'], [$body->title, $body->text, $body->format]);
            self::assertCount(1, $this->history);
        } finally {
            unlink($path);
        }
    }

    public function testThePlainTextThreshold(): void
    {
        $dn = $this->client([self::json(202, self::item())], ['inlineThreshold' => 10]);
        $dn->collections->addDocument(self::COL, Document::fromData('123456789', 'text/plain', 'n.txt'));
        self::assertSame('text', $this->sentBody()->format);

        // At the threshold, and not UTF-8, a text file is uploaded.
        foreach (['1234567890', "\xff\xfe abc"] as $bytes) {
            $dn = $this->client([self::presigned(['upl_t']), new Response(200), self::json(202, self::item())], ['inlineThreshold' => 10]);
            $dn->collections->addDocument(self::COL, Document::fromData($bytes, 'text/plain', 'n.txt'));
            self::assertSame('/v1/uploads', $this->sent(0)->getUri()->getPath());
            self::assertSame('{"upload_id":"upl_t"}', (string) $this->sent(2)->getBody());
        }
    }

    public function testAPdfIsUploadedThenNamedByItsUploadId(): void
    {
        $pdf = self::pdf();
        $dn = $this->client([self::presigned(['upl_p']), new Response(200), self::json(202, self::item('queued', ['source' => 'file',
            'file' => ['filename' => 'claims.pdf', 'content_type' => 'application/pdf', 'byte_size' => 2000, 'pages' => null, 'original_held' => true]]))],
            ['headers' => ['x-team' => 'ops']]);
        $item = $dn->collections->addDocument(self::COL, Document::fromData($pdf, filename: 'claims.pdf'), reference: 'claims');
        $presign = $this->sentBody(0);
        self::assertSame(['knowledge', 'application/pdf', 2000, 'claims.pdf'], [$presign->files[0]->purpose, $presign->files[0]->content_type, $presign->files[0]->byte_size, $presign->files[0]->filename]);
        self::assertSame(base64_encode(md5($pdf, true)), $presign->files[0]->checksum);
        self::assertSame(base64_encode(hash('sha256', $pdf, true)), $presign->files[0]->sha256);
        $put = $this->sent(1);
        self::assertSame(['PUT', 'storage.example.test', $pdf, '', '', '*'], [$put->getMethod(), $put->getUri()->getHost(), (string) $put->getBody(),
            $put->getHeaderLine('Authorization'), $put->getHeaderLine('x-team'), $put->getHeaderLine('If-None-Match')]);
        self::assertSame(120.0, $this->history[1]['options']['timeout'] ?? null);
        self::assertSame('{"upload_id":"upl_p","reference":"claims"}', (string) $this->sent(2)->getBody());
        self::assertSame(['file', true], [$item->source, $item->file['original_held'] ?? null]);
    }

    public function testTheUploadTimeoutGivenHoldsForDocuments(): void
    {
        $dn = $this->client([self::presigned(['upl_p']), new Response(200), self::json(202, self::item())], ['uploadTimeout' => 30.0]);
        $dn->collections->addDocument(self::COL, Document::fromData(self::pdf()));
        self::assertSame(30.0, $this->history[1]['options']['timeout'] ?? null);
        self::assertSame(60.0, (new \Bynn\DecisionNode\DecisionNode(apiKey: 'k'))->uploadTimeout);
    }

    public function testTypesByNameAndByBytes(): void
    {
        $docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        $cases = [
            [Document::fromData('PK..', filename: 'Handbook.DOCX'), $docx],
            [Document::fromData('PK..', $docx), $docx],
            [Document::fromData("\x89PNG\r\n\x1a\n...."), 'image/png'],
            [Document::fromData("\xFF\xD8\xFF\xE0"), 'image/jpeg'],
            [Document::fromData("II*\0...."), 'image/tiff'],
            [Document::fromData("MM\0*...."), 'image/tiff'],
            [Document::fromData('RIFF....WEBPVP8 '), 'image/webp'],
            [Document::fromData('<p>x</p>', filename: 'a.htm'), 'text/html'],
        ];
        foreach ($cases as [$document, $type]) {
            $dn = $this->client([self::presigned(['upl_x']), new Response(200), self::json(202, self::item())]);
            $dn->collections->addDocument(self::COL, $document, title: 'T');
            self::assertSame($type, $this->sentBody(0)->files[0]->content_type);
            self::assertSame('{"upload_id":"upl_x","title":"T"}', (string) $this->sent(2)->getBody());
        }
    }

    public function testAStreamIsReadToItsEnd(): void
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, self::pdf(3000));
        rewind($stream);
        $dn = $this->client([self::presigned(['upl_s']), new Response(200), self::json(202, self::item())]);
        $dn->collections->addDocument(self::COL, Document::fromStream($stream), title: 'Scan');
        self::assertSame(3000, $this->sentBody(0)->files[0]->byte_size);
        self::assertFalse(property_exists($this->sentBody(0)->files[0], 'filename'));

        $dn = $this->client([self::presigned(['upl_s']), new Response(200), self::json(202, self::item())]);
        $dn->collections->addDocument(self::COL, Document::fromStream(Utils::streamFor(self::pdf(2500))), title: 'Scan');
        self::assertSame(2500, $this->sentBody(0)->files[0]->byte_size);
    }

    public function testAStaleUploadIsUploadedAgainOnceWithANewKey(): void
    {
        $stale = self::json(400, ['detail' => ['error_type' => 'upload_expired', 'message' => 'upload upl_1 has expired.']]);
        $dn = $this->client([self::presigned(['upl_1']), new Response(200), $stale, self::presigned(['upl_2']), new Response(200), self::json(202, self::item())]);
        $dn->collections->addDocument(self::COL, Document::fromData(self::pdf()), title: 'T');
        self::assertCount(6, $this->history);
        self::assertSame('upl_2', $this->sentBody(5)->upload_id);
        self::assertNotSame($this->sent(2)->getHeaderLine('Idempotency-Key'), $this->sent(5)->getHeaderLine('Idempotency-Key'));

        $dn = $this->client([self::presigned(['upl_1']), new Response(200), $stale, self::presigned(['upl_2']), new Response(200), $stale]);
        try {
            $dn->collections->addDocument(self::COL, Document::fromData(self::pdf()), title: 'T');
            self::fail('expected the second stale answer');
        } catch (BadRequestError $e) {
            self::assertSame('upload_expired', $e->errorType);
        }

        // An upload id the caller gave is raised at once, as a purpose mismatch always is.
        foreach ([$stale, self::json(400, ['detail' => ['error_type' => 'upload_purpose_mismatch', 'message' => 'upload upl_1 is an image upload.']])] as $answer) {
            $dn = $this->client([$answer]);
            try {
                $dn->collections->addDocument(self::COL, Document::fromUploadId('upl_1'));
                self::fail('expected a BadRequestError');
            } catch (BadRequestError) {
                self::assertCount(1, $this->history);
            }
        }
        $dn = $this->client([self::presigned(['upl_1']), new Response(200), self::json(400, ['detail' => ['error_type' => 'upload_purpose_mismatch', 'message' => 'm']])]);
        try {
            $dn->collections->addDocument(self::COL, Document::fromData(self::pdf()), title: 'T');
            self::fail('expected a BadRequestError');
        } catch (BadRequestError) {
            self::assertCount(3, $this->history);
        }
    }

    public function testReplaceAndUpdate(): void
    {
        $dn = $this->client([self::json(202, self::item()), self::json(200, self::item()), self::json(202, self::item())]);
        $dn->collections->replaceDocument(self::COL, 'itm_1', Document::fromText('New text.'), title: 'Claims policy', wait: true);
        self::assertSame(['PUT', self::ITEMS . '/itm_1', 'wait=true'], [$this->sent()->getMethod(), $this->sent()->getUri()->getPath(), $this->sent()->getUri()->getQuery()]);
        self::assertNotSame('', $this->sent()->getHeaderLine('Idempotency-Key'));

        $dn->collections->updateItem(self::COL, 'itm_1', pinned: true, pinRank: 2);
        self::assertSame(['PATCH', '{"pinned":true,"pin_rank":2}'], [$this->sent(1)->getMethod(), (string) $this->sent(1)->getBody()]);
        $dn->collections->updateItem(self::COL, 'itm_1', label: null, title: 'Claims 2026');
        self::assertSame('{"label":null,"title":"Claims 2026"}', (string) $this->sent(2)->getBody());
    }

    public function testReadingDocumentsAndChunks(): void
    {
        $dn = $this->client([
            self::json(200, self::item('ready', ['text' => "# Receipts\n\nNo claim."])),
            self::json(200, self::item('ready')),
            self::json(200, ['chunks' => [['chunk' => 0, 'heading' => 'Receipts', 'byte_start' => 0, 'byte_end' => 22, 'page_start' => null,
                'page_end' => null, 'tokens' => 6, 'text' => "# Receipts\n\nNo claim."]], 'offset' => 0, 'limit' => 1, 'total' => 3, 'next_offset' => 1]),
            self::json(200, ['id' => 'itm_1', 'deleted' => true]),
        ]);
        $item = $dn->collections->getItem(self::COL, 'itm_1', includeText: true);
        self::assertSame(['include=text', "# Receipts\n\nNo claim."], [$this->sent(0)->getUri()->getQuery(), $item->text]);
        $dn->collections->getItem(self::COL, 'itm_1');
        self::assertSame('', $this->sent(1)->getUri()->getQuery());

        $page = $dn->collections->listChunks(self::COL, 'itm_1', offset: 0, limit: 1);
        self::assertSame([self::ITEMS . '/itm_1/chunks', 'offset=0&limit=1'], [$this->sent(2)->getUri()->getPath(), $this->sent(2)->getUri()->getQuery()]);
        self::assertSame([1, 'Receipts', 22, 3, 1], [count($page->chunks), $page->chunks[0]->heading, $page->chunks[0]->byteEnd, $page->total, $page->nextOffset]);

        self::assertTrue($dn->collections->deleteItem(self::COL, 'itm_1')->deleted);

        $this->expectException(BadRequestError::class);
        $dn->collections->listChunks(self::COL, 'itm_1', limit: 101);
    }

    public function testWaitUntilReady(): void
    {
        $dn = $this->client([
            self::json(200, self::item('queued')),
            self::json(200, self::item('processing', ['stage' => 'embedding', 'progress' => ['chunks_embedded' => 3, 'chunks_total' => 9, 'pages' => null]])),
            self::json(200, self::item('ready')),
        ]);
        $item = $dn->collections->waitUntilReady(self::COL, 'itm_1');
        self::assertInstanceOf(CollectionItem::class, $item);
        self::assertSame('ready', $item->status);
        self::assertSame([null, 1000, 1500], array_map(static fn (array $h): mixed => $h['options']['delay'] ?? null, $this->history));

        // A failed document is a value, and an unknown status is waited on.
        $dn = $this->client([self::json(200, self::item('uploading')), self::json(200, self::item('failed', ['error' => 'embedding_rejected']))]);
        $failed = $dn->collections->waitUntilReady(self::COL, 'itm_1', pollInterval: 0.25);
        self::assertInstanceOf(CollectionItem::class, $failed);
        self::assertSame(['failed', 'embedding_rejected'], [$failed->status, $failed->error]);
        self::assertSame(250, $this->history[1]['options']['delay'] ?? null);

        $col = ['id' => self::COL, 'type' => 'text', 'name' => 'K'];
        $dn = $this->client([
            self::json(200, $col + ['status_counts' => ['queued' => 0, 'processing' => 2, 'ready' => 1, 'failed' => 0]]),
            self::json(200, $col + ['status_counts' => ['queued' => 0, 'processing' => 0, 'ready' => 3, 'failed' => 1]]),
        ]);
        $collection = $dn->collections->waitUntilReady(self::COL);
        self::assertInstanceOf(Collection::class, $collection);
        self::assertSame('/v1/collections/' . self::COL, $this->sent(1)->getUri()->getPath());

        $dn = $this->client(array_fill(0, 10, self::json(200, self::item('queued'))));
        try {
            $dn->collections->waitUntilReady(self::COL, 'itm_1', timeout: 3.0);
            self::fail('expected a TimeoutError');
        } catch (TimeoutError) {
            // Reads after 0, 1, 2.5 and 3 s: the last wait is what the timeout leaves.
            self::assertCount(4, $this->history);
            self::assertSame(500, $this->history[3]['options']['delay'] ?? null);
        }
    }

    public function testDeleteFailedItems(): void
    {
        $dn = $this->client([self::json(200, ['deleted' => 3])]);
        $done = $dn->collections->deleteFailed(self::COL);
        self::assertSame(['DELETE', self::ITEMS, 'status=failed', 3], [$this->sent()->getMethod(), $this->sent()->getUri()->getPath(), $this->sent()->getUri()->getQuery(), $done->deleted]);
    }

    public function testWaitUntilReadyArguments(): void
    {
        $dn = $this->client([]);
        foreach ([[300.0, 0.05], [0.0, 1.0], [-1.0, 1.0]] as [$timeout, $interval]) {
            try {
                $dn->collections->waitUntilReady(self::COL, 'itm_1', $timeout, $interval);
                self::fail('expected an argument error');
            } catch (\InvalidArgumentException) {
                self::assertCount(0, $this->history);
            }
        }
    }

    public function testSearch(): void
    {
        $reading = self::reading(['text' => 'Reject any claim over 500 SEK without a receipt.']);
        unset($reading['truncated'], $reading['passages'][0]['rendered']);
        $dn = $this->client([self::json(200, $reading), self::json(200, $reading), self::json(200, ['passages' => 'none'])]);
        $found = $dn->collections->search(self::COL, 'receipts', scope: 'M-42', match: ['region' => 'SE'],
            filter: ['key' => 'year', 'op' => 'gte', 'value' => 2026], topK: 5, pinned: false, maxTokens: 'auto');
        self::assertSame('/v1/collections/' . self::COL . '/search', $this->sent()->getUri()->getPath());
        self::assertSame('{"query":"receipts","scope":"M-42","match":{"region":"SE"},"filter":{"key":"year","op":"gte","value":2026},"top_k":5,"pinned":false,"max_tokens":"auto"}', (string) $this->sent()->getBody());
        self::assertSame(['Reject any claim over 500 SEK without a receipt.', null, null], [$found->passages[0]->text, $found->passages[0]->rendered, $found->truncated]);

        $dn->collections->search(self::COL, 'receipts');
        self::assertSame('{"query":"receipts"}', (string) $this->sent(1)->getBody());

        $this->expectException(ServerError::class);
        $dn->collections->search(self::COL, 'receipts');
    }

    public function testTheKnowledgeBooster(): void
    {
        self::assertSame(['name' => 'knowledge', 'options' => ['collection' => self::COL]], Booster::knowledge(self::COL)->jsonSerialize());
        self::assertSame(
            '{"name":"knowledge","require":true,"options":{"collection":"' . self::COL . '","query":"q","scope":"M-42","match":{"region":"SE"},"filter":{"and":[]},"top_k":0,"max_tokens":512,"pinned":false,"min_score":0.5,"include_text":true}}',
            json_encode(Booster::knowledge(self::COL, query: 'q', scope: 'M-42', match: ['region' => 'SE'], filter: ['and' => []], topK: 0,
                maxTokens: 512, pinned: false, minScore: 0.5, includeText: true, require: true)),
        );

        $evidence = ['knowledge' => ['version' => 'knowledge-v1', 'status' => 'ok', 'latency_ms' => 141, 'billed_calls' => 1, 'results' => [self::reading()]]];
        $dn = $this->client([self::json(200, ['model' => 'decisionnode-moe', 'answers' => ['q' => ['type' => 'truth', 'truth' => 0.9]], 'evidence' => $evidence])]);
        $d = $dn->decide('claim 812', ['q' => truth('Pay it?')], boosters: [Booster::knowledge(self::COL, scope: 'M-42', require: true)]);
        self::assertSame(10.0, $this->history[0]['options']['timeout'] ?? null);
        $reading = $d->evidence['knowledge']->results[0];
        self::assertInstanceOf(KnowledgeReading::class, $reading);
        self::assertSame([self::COL, 'Claims policy', 41, 'M-42', [], 1, false], [$reading->collection, $reading->collectionName, $reading->corpus['version'] ?? null,
            $reading->scope['value'] ?? null, $reading->warnings, $reading->omitted['over_budget'] ?? null, $reading->truncated]);
        $p = $reading->passages[0];
        self::assertSame([1, true, 'itm_1', 3, 1840, 2310, null, 'Receipts', true, null], [$p->n, $p->pinned, $p->item, $p->chunk, $p->byteStart, $p->byteEnd, $p->score, $p->heading, $p->rendered, $p->text]);

        // A malformed reading or passage is a generic reading; the decision does not fail.
        foreach ([['collection' => 1] + self::reading(), self::reading(['byte_start' => '1840'])] as $bad) {
            $evidence['knowledge']['results'] = [$bad];
            $dn = $this->client([self::json(200, ['model' => 'm', 'answers' => ['q' => ['type' => 'truth', 'truth' => 0.9]], 'evidence' => $evidence])]);
            self::assertInstanceOf(UnknownReading::class, $dn->decide('s', ['q' => truth('?')])->evidence['knowledge']->results[0]);
        }
    }

    public function testTheKnowledgeOverloadsAndRateLimits(): void
    {
        $delayed = self::json(529, ['detail' => ['error_type' => 'booster_unavailable', 'message' => 'm', 'details' => ['reason' => 'ingestion_delayed']]], ['Retry-After' => '60']);
        $dn = $this->client([$delayed, self::truthBody()]);
        try {
            $dn->decide('s', ['q' => truth('?')], boosters: [Booster::knowledge(self::COL, require: true)]);
            self::fail('expected an OverloadedError');
        } catch (OverloadedError $e) {
            self::assertSame(['booster_unavailable', 'ingestion_delayed', 60.0], [$e->errorType, $e->details['reason'] ?? null, $e->retryAfter]);
            self::assertCount(1, $this->history);
        }

        $pending = self::json(529, ['detail' => ['error_type' => 'booster_unavailable', 'message' => 'm']], ['Retry-After' => '5', 'x-decisionnode-boosters' => 'knowledge=skipped:3']);
        $dn = $this->client([$pending, self::truthBody()]);
        $dn->decide('s', ['q' => truth('?')], boosters: [Booster::knowledge(self::COL, require: true)]);
        self::assertSame(5000, $this->history[1]['options']['delay'] ?? null);

        $queue = self::json(429, ['detail' => ['error_type' => 'rate_limit_error', 'message' => 'm', 'details' => ['reason' => 'ingest_queue_full']]], ['Retry-After' => '60']);
        $dn = $this->client([$queue, $queue, $queue], ['maxRetries' => 1]);
        try {
            $dn->collections->addDocument(self::COL, Document::fromText('x'), title: 'T');
            self::fail('expected a RateLimitError');
        } catch (RateLimitError $e) {
            self::assertSame(['ingest_queue_full', 2], [$e->details['reason'] ?? null, count($this->history)]);
            self::assertSame($this->sent(0)->getHeaderLine('Idempotency-Key'), $this->sent(1)->getHeaderLine('Idempotency-Key'));
        }
    }

    public function testUploadDocuments(): void
    {
        $dn = $this->client([self::presigned(['upl_a', 'upl_b']), new Response(200), new Response(200)]);
        $ids = $dn->uploadDocuments([Document::fromData(self::pdf(), filename: 'a.pdf'), Document::fromText('# Note', Document::MARKDOWN)]);
        self::assertSame(['upl_a', 'upl_b'], $ids);
        $files = $this->sentBody(0)->files;
        self::assertSame(['application/pdf', 'text/markdown', 'knowledge', 'knowledge'], [$files[0]->content_type, $files[1]->content_type, $files[0]->purpose, $files[1]->purpose]);
        self::assertCount(3, $this->history);

        try {
            $dn->uploadDocuments(array_fill(0, 17, Document::fromText('x')));
            self::fail('expected a BadRequestError');
        } catch (BadRequestError $e) {
            self::assertSame('files: 1 to 16 files, got 17.', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $dn->uploadDocuments([Document::fromUploadId('upl_a')]);
    }

    public function testImageUploadsCarryNoPurpose(): void
    {
        $dn = $this->client([self::presigned(['upl_i']), new Response(200)]);
        $dn->upload([\Bynn\DecisionNode\Image::fromPath('a', __DIR__ . '/../fixtures/images/3x2.png')]);
        self::assertFalse(property_exists($this->sentBody(0)->files[0], 'purpose'));
    }

    public function testLocalChecks(): void
    {
        $dn = $this->client([]);
        $entry = static function (callable $call): array {
            try {
                $call();
            } catch (ValidationError $e) {
                return $e->entries[0] ?? [];
            }
            self::fail('expected a ValidationError');
        };
        self::assertSame(['type' => 'literal_error', 'loc' => ['body', 'type'], 'msg' => "Input should be 'face' or 'text'", 'input' => 'plate', 'ctx' => ['expected' => "'face' or 'text'"]],
            $entry(static fn () => $dn->collections->create('K', 'plate')));
        self::assertSame(['type' => 'string_pattern_mismatch', 'loc' => ['body', 'scope_key'], 'msg' => "String should match pattern '^[a-z0-9_]{1,40}$'", 'input' => 'Mission'],
            $entry(static fn () => $dn->collections->create('K', 'text', 'Mission')));
        self::assertSame(['type' => 'missing', 'loc' => ['body', 'title'], 'msg' => 'Field required', 'input' => null],
            $entry(static fn () => $dn->collections->addDocument(self::COL, Document::fromText('x'))));
        self::assertSame(['type' => 'missing', 'loc' => ['body', 'title'], 'msg' => 'Field required', 'input' => null],
            $entry(static fn () => $dn->collections->addDocument(self::COL, Document::fromData('words', 'text/plain'))));
        self::assertSame(['type' => 'string_too_long', 'loc' => ['body', 'title'], 'msg' => 'A title is 1 to 200 characters', 'input' => '   '],
            $entry(static fn () => $dn->collections->addDocument(self::COL, Document::fromText('x'), title: '   ')));
        self::assertSame('string_too_long', $entry(static fn () => $dn->collections->updateItem(self::COL, 'itm_1', title: str_repeat('é', 201)))['type']);
        self::assertSame([], $this->collectionsOk($dn, str_repeat('é', 200)));
        self::assertSame(['type' => 'int_range', 'loc' => ['body', 'pin_rank'], 'msg' => 'Input should be an integer from 0 to 1000', 'input' => 1001],
            $entry(static fn () => $dn->collections->addDocument(self::COL, Document::fromText('x'), title: 'T', pinRank: 1001)));
        self::assertSame(['type' => 'literal_error', 'loc' => ['body', 'format'], 'msg' => "Input should be 'markdown' or 'text'", 'input' => 'html'],
            $entry(static fn () => $dn->collections->addDocument(self::COL, Document::fromText('x', 'html'), title: 'T')));
        self::assertSame(['type' => 'string_pattern_mismatch', 'loc' => ['body', 'reference'], 'msg' => "String should match pattern '^[A-Za-z0-9_.:-]{1,64}$'", 'input' => 'a b', 'ctx' => ['pattern' => '^[A-Za-z0-9_.:-]{1,64}$']],
            $entry(static fn () => $dn->collections->addDocument(self::COL, Document::fromText('x'), title: 'T', reference: 'a b')));
        self::assertSame(['type' => 'string_too_short', 'loc' => ['body', 'label'], 'msg' => 'String should have at least 1 character', 'input' => '', 'ctx' => ['min_length' => 1]],
            $entry(static fn () => $dn->collections->addDocument(self::COL, Document::fromText('x'), title: 'T', label: '')));
        self::assertSame(['type' => 'string_too_short', 'loc' => ['body', 'text'], 'msg' => 'The text is empty', 'input' => ''],
            $entry(static fn () => $dn->collections->addDocument(self::COL, Document::fromText(''), title: 'T')));

        $bad = static function (callable $call): string {
            try {
                $call();
            } catch (BadRequestError $e) {
                self::assertSame('api_usage_error', $e->errorType);
                self::assertNull($e->status);

                return $e->getMessage();
            }
            self::fail('expected a BadRequestError');
        };
        self::assertSame('files[0].byte_size: 52428801 is over the limit of 52428800 bytes.',
            $bad(static fn () => $dn->collections->addDocument(self::COL, Document::fromData(self::pdf(52_428_801)), title: 'T')));
        self::assertSame('files[0].byte_size: must be an integer from 1 to 52428800.', $bad(static fn () => $dn->collections->addDocument(self::COL, Document::fromData('', 'application/pdf'), title: 'T')));
        self::assertStringEndsWith(', image/webp, got application/zip.',
            $bad(static fn () => $dn->collections->addDocument(self::COL, Document::fromData('x', 'application/zip'), title: 'T')));
        foreach ([Document::fromData('PK..'), Document::fromData('x', filename: 'a.zip')] as $untold) {
            try {
                $dn->collections->addDocument(self::COL, $untold, title: 'T');
                self::fail('expected an argument error');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertCount(0, $this->history);
    }

    /**
     * A title of 200 characters passes the local checks.
     *
     * @return list<mixed>
     */
    private function collectionsOk(\Bynn\DecisionNode\DecisionNode $dn, string $title): array
    {
        try {
            $dn->collections->addDocument(self::COL, Document::fromText('x'), title: $title);
        } catch (\OutOfBoundsException) {
            // The mock queue is empty: the call got past the checks.
            return [];
        }

        return ['sent'];
    }
}
