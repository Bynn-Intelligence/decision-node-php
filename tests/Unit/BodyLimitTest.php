<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\BatchRequest;
use Bynn\DecisionNode\Exception\RequestTooLargeError;
use Bynn\DecisionNode\Image;

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

    public function testImagesAreMeasuredAsTheyWillBeSentBeforeAnyUpload(): void
    {
        $png = (string) file_get_contents(__DIR__ . '/../fixtures/images/3x2.png');
        foreach ([524_288, 0] as $threshold) {
            $request = static fn (string $state): array => ['state' => $state, 'questions' => ['q' => truth('?')], 'images' => [Image::fromData('scan', $png)]];
            // The overhead with the image inline, or named by a 36-character upload id.
            $dn = $this->client([self::truthBody()], ['inlineThreshold' => $threshold]);
            $wire = $threshold === 0
                ? '{"id":"scan","upload_id":"' . str_repeat('u', 36) . '"}'
                : json_encode(Image::fromData('scan', $png)->toArray(), JSON_UNESCAPED_SLASHES);
            $overhead = strlen('{"model":"decisionnode-latest","state":"","questions":{"q":{"type":"truth","instructions":"?"}},"images":[' . $wire . ']}');
            try {
                $dn->checkRequest('decide', $request(self::stateOf(self::LIMIT - $overhead + 1)));
                self::fail('one byte over, threshold ' . $threshold);
            } catch (RequestTooLargeError) {
            }
            $dn->checkRequest('decide', $request(self::stateOf(self::LIMIT - $overhead)));
            self::assertCount(0, $this->history, 'nothing sent or uploaded to measure');
        }
    }

    private static function stateOf(int $length): string
    {
        return str_repeat('x', $length);
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
