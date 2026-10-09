<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\BatchRequest;
use Bynn\DecisionNode\Exception\RequestTooLargeError;

use function Bynn\DecisionNode\truth;

final class BodyLimitTest extends TestCase
{
    private const LIMIT = 26_214_400;

    private static function state(int $extra): string
    {
        $overhead = strlen('{"model":"decisionnode-latest","state":"","questions":{"q":{"type":"truth","instructions":"?"}}}');

        return str_repeat('x', self::LIMIT - $overhead + $extra);
    }

    public function testABodyAtTheLimitIsSentAndOneByteMoreIsNot(): void
    {
        $dn = $this->client([self::truthBody()]);
        $dn->decide(self::state(0), ['q' => truth('?')]);
        self::assertSame(self::LIMIT, strlen((string) $this->sent()->getBody()));

        $over = self::state(1);
        $calls = [
            'decide' => static fn () => $dn->decide($over, ['q' => truth('?')]),
            'checkRequest' => static fn () => $dn->checkRequest('decide', ['state' => $over, 'questions' => ['q' => truth('?')]]),
            'decideMany' => static fn () => $dn->decideMany([['questions' => ['q' => truth('?')]], ['state' => $over, 'questions' => ['q' => truth('?')]]]),
            'batch line' => static fn () => $dn->batches->create([new BatchRequest('a', ['q' => truth('?')], state: $over)]),
        ];
        foreach ($calls as $what => $call) {
            try {
                $call();
                self::fail($what . ' was not refused');
            } catch (RequestTooLargeError $e) {
                self::assertNull($e->status, $what);
                self::assertNull($e->requestId, $what);
                self::assertSame('request_too_large_error', $e->errorType, $what);
                self::assertStringStartsWith('The request body is over 26,214,400 bytes (25 MiB).', $e->getMessage(), $what);
            }
        }
        self::assertCount(1, $this->history, 'nothing more was sent');
    }

    public function testTheApis413IsNotRetried(): void
    {
        $dn = $this->client([self::json(413, ['detail' => ['error_type' => 'request_too_large_error', 'message' => 'too large']], ['x-request-id' => 'req_413'])]);
        try {
            $dn->decide('', ['q' => truth('?')]);
            self::fail('413');
        } catch (RequestTooLargeError $e) {
            self::assertSame([413, 'req_413'], [$e->status, $e->requestId]);
            self::assertCount(1, $this->history);
        }
    }
}
