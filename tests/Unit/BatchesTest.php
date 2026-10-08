<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\Batch;
use Bynn\DecisionNode\BatchRequest;
use Bynn\DecisionNode\BatchResult;
use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\ConnectionError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\ServerError;
use Bynn\DecisionNode\Exception\TimeoutError;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

use function Bynn\DecisionNode\choice;
use function Bynn\DecisionNode\truth;

final class BatchesTest extends TestCase
{
    /**
     * @param array<string, mixed> $fields
     */
    private static function batch(string $state, array $fields = [], int $status = 200, array $headers = []): Response
    {
        return self::json($status, $fields + [
            'id' => 'batch_01J9ZB8Q3T',
            'state' => $state,
            'counters' => ['requests' => 1, 'queued' => 0, 'in_progress' => 0, 'completed' => 0, 'failed' => 0, 'expired' => 0, 'cancelled' => 0],
            'created_at' => '2026-10-06T09:12:04Z',
            'finalized_at' => null,
            'expires_at' => null,
            'ended_at' => in_array($state, ['completed', 'failed', 'expired', 'cancelled'], true) ? '2026-10-06T10:00:00Z' : null,
            'results_url' => null,
            'results_expire_at' => null,
            'price_factor' => 0.5,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            'error' => null,
        ], $headers);
    }

    public function testCreateSendsJsonLines(): void
    {
        $dn = $this->client([self::batch('draft', [], 201)]);
        $batch = $dn->batches->create([
            ['custom_id' => 't1', 'state' => 'I was charged twice.', 'questions' => ['route' => choice('Where?', ['billing', 'bug'])]],
            new BatchRequest('t2', ['refund' => truth('Refund?')], model: 'decisionnode-flash-latest'),
        ]);
        self::assertInstanceOf(Batch::class, $batch);
        self::assertSame('draft', $batch->state);
        self::assertSame(0.5, $batch->priceFactor);
        $sent = $this->sent();
        self::assertSame('application/x-ndjson', $sent->getHeaderLine('Content-Type'));
        $lines = explode("\n", rtrim((string) $sent->getBody(), "\n"));
        self::assertCount(2, $lines);
        self::assertSame('{"custom_id":"t1","model":"decisionnode-latest","state":"I was charged twice.","questions":{"route":{"type":"choice","instructions":"Where?","criteria":{"billing":null,"bug":null}}}}', $lines[0]);
        self::assertSame('{"custom_id":"t2","model":"decisionnode-flash-latest","state":"","questions":{"refund":{"type":"truth","instructions":"Refund?"}}}', $lines[1]);
    }

    public function testAnEmptyDraftHasAnEmptyBody(): void
    {
        $dn = $this->client([self::batch('draft', [], 201)]);
        $dn->batches->create();
        self::assertSame('', (string) $this->sent()->getBody());
    }

    public function testCreateIsNotRetriedAfterALostAnswerOrA500(): void
    {
        $dn = $this->client([new ConnectException('reset', new Request('POST', 'https://api.example.test/v1/batches'))]);
        try {
            $dn->batches->create([]);
            self::fail('no error');
        } catch (ConnectionError) {
        }
        self::assertCount(1, $this->history);

        $dn = $this->client([self::json(500, ['detail' => ['error_type' => 'api_error', 'message' => 'x']])]);
        try {
            $dn->batches->create([]);
            self::fail('no error');
        } catch (ServerError) {
        }
        self::assertCount(1, $this->history);
    }

    public function testCreateIsRetriedOn529(): void
    {
        $dn = $this->client([self::json(529, ['detail' => ['error_type' => 'overloaded_error', 'message' => 'x']], ['retry-after-ms' => '1']), self::batch('draft', [], 201)]);
        $dn->batches->create([]);
        self::assertCount(2, $this->history);
    }

    public function testAddSplitsAt10MiB(): void
    {
        $dn = $this->client([self::batch('draft'), self::batch('draft')]);
        $big = str_repeat('x', 4_000_000);
        $dn->batches->add('batch_01J9ZB8Q3T', [
            ['custom_id' => 'a', 'state' => $big, 'questions' => ['q' => truth('?')]],
            ['custom_id' => 'b', 'state' => $big, 'questions' => ['q' => truth('?')]],
            ['custom_id' => 'c', 'state' => $big, 'questions' => ['q' => truth('?')]],
        ]);
        self::assertCount(2, $this->history);
        self::assertSame(2, substr_count((string) $this->sent(0)->getBody(), "\n"));
        self::assertSame(1, substr_count((string) $this->sent(1)->getBody(), "\n"));
        self::assertLessThanOrEqual(10_485_760, $this->sent(0)->getBody()->getSize());
    }

    public function testAddChecksLocallyAndNeedsACustomId(): void
    {
        $dn = $this->client([]);
        try {
            $dn->batches->add('b', [['custom_id' => 'a', 'questions' => ['q' => truth()]]]);
            self::fail('no error');
        } catch (BadRequestError) {
        }
        try {
            $dn->batches->add('b', [['questions' => ['q' => truth('?')]]]);
            self::fail('no error');
        } catch (BadRequestError $e) {
            self::assertSame('Line 1 has no custom_id.', $e->getMessage());
            self::assertSame('api_usage_error', $e->errorType);
        }
        try {
            $dn->batches->add('b', [['custom_id' => 'a', 'questions' => ['q' => truth('?')]], ['custom_id' => 'a', 'questions' => ['q' => truth('?')]]]);
            self::fail('no error');
        } catch (BadRequestError $e) {
            self::assertSame('Line 2: custom_id "a" is already in the batch.', $e->getMessage());
        }
        try {
            $dn->batches->add('b', []);
            self::fail('no error');
        } catch (BadRequestError $e) {
            self::assertSame('The call holds no requests.', $e->getMessage());
        }
        self::assertSame([], $this->history);
    }

    public function testFinalizeConflictIsReadWithGet(): void
    {
        $conflict = self::json(409, ['detail' => ['error_type' => 'invalid_batch_state', 'message' => 'Batch is queued.']]);
        $dn = $this->client([$conflict, self::batch('queued')]);
        self::assertSame('queued', $dn->batches->finalize('batch_01J9ZB8Q3T')->state);

        $dn = $this->client([$conflict, self::batch('draft')]);
        try {
            $dn->batches->finalize('batch_01J9ZB8Q3T');
            self::fail('no error');
        } catch (DecisionNodeError $e) {
            self::assertSame(409, $e->status);
            self::assertSame('invalid_batch_state', $e->errorType);
        }
    }

    public function testListSendsOffsetAndLimit(): void
    {
        $dn = $this->client([self::json(200, ['batches' => [], 'offset' => 20, 'limit' => 10, 'total' => 3, 'next_offset' => null])]);
        $page = $dn->batches->list(20, 10);
        self::assertSame('offset=20&limit=10', $this->sent()->getUri()->getQuery());
        self::assertSame(3, $page->total);
        self::assertNull($page->nextOffset);
    }

    public function testResultsLines(): void
    {
        $text = '{"custom_id":"a","status":200,"body":{"model":"decisionnode-1.0","answers":{"q":{"type":"truth","truth":0.8200000000000001}},"usage":{"input_tokens":4,"output_tokens":0}},"headers":{"X-Request-Id":"req_1"}}' . "\n"
            . '{"custom_id":"b","status":400,"body":{"detail":{"error_type":"api_usage_error","message":"Unknown model"}},"headers":{"x-decisionnode-error":"unknown model"}}' . "\n"
            . '{"custom_id":"c","status":"expired","body":null,"headers":{}}' . "\n";
        $dn = $this->client([new Response(200, ['Content-Type' => 'application/x-ndjson'], $text)]);
        $results = $dn->batches->results('batch_01J9ZB8Q3T');
        self::assertCount(3, $results);
        self::assertContainsOnlyInstancesOf(BatchResult::class, $results);
        self::assertSame(0.8200000000000001, $results[0]->decision?->answers['q']->truth ?? null);
        self::assertSame('req_1', $results[0]->decision?->requestId);
        self::assertInstanceOf(BadRequestError::class, $results[1]->error);
        self::assertSame('unknown model', $results[1]->error->reason);
        self::assertSame('expired', $results[2]->status);
        self::assertNull($results[2]->decision);
        self::assertNull($results[2]->error);
    }

    public function testRunBatchPollsAtTheIntervalOrTheServersRetryAfter(): void
    {
        $dn = $this->client([
            self::batch('draft', [], 201),
            self::batch('draft'),
            self::batch('queued'),
            self::batch('running', [], 200, ['Retry-After' => '30']),
            self::batch('completed'),
            new Response(200, [], '{"custom_id":"t1","status":200,"body":{"answers":{}},"headers":{}}' . "\n"),
        ]);
        [$batch, $results] = $dn->runBatch([['custom_id' => 't1', 'state' => 's', 'questions' => ['q' => truth('?')]]], pollInterval: 10);
        self::assertSame('completed', $batch->state);
        self::assertCount(1, $results);
        $delays = array_map(static fn (array $h): mixed => $h['options']['delay'] ?? null, $this->history);
        self::assertSame([null, null, null, 10000.0, 30000.0, null], $delays);
    }

    public function testRunBatchCancelsTheDraftWhenAddFails(): void
    {
        $dn = $this->client([
            self::batch('draft', [], 201),
            self::json(400, ['detail' => ['error_type' => 'api_usage_error', 'message' => 'dup']]),
            self::batch('cancelled'),
        ], ['maxRetries' => 0]);
        try {
            $dn->runBatch([['custom_id' => 't1', 'questions' => ['q' => truth('?')]]], pollInterval: 10);
            self::fail('no error');
        } catch (BadRequestError $e) {
            self::assertSame('dup', $e->getMessage());
        }
        self::assertStringEndsWith('/cancel', $this->sent(2)->getUri()->getPath());
    }

    public function testRunBatchRefusesAShortPollInterval(): void
    {
        $dn = $this->client([]);
        $this->expectException(\InvalidArgumentException::class);
        $dn->runBatch([], pollInterval: 5);
    }

    public function testRunBatchTimesOut(): void
    {
        $dn = $this->client([self::batch('draft', [], 201), self::batch('queued'), self::batch('running'), self::batch('running')]);
        $this->expectException(TimeoutError::class);
        $dn->runBatch([], pollInterval: 10, timeout: 15);
    }
}
