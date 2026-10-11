<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\Booster;
use Bynn\DecisionNode\DecisionNode;
use Bynn\DecisionNode\Exception\OverloadedError;
use Bynn\DecisionNode\Exception\TimeoutError;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;

use function Bynn\DecisionNode\truth;

final class OcrTimeoutTest extends TestCase
{
    private static function timedOut(): ConnectException
    {
        return new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://api.example.test/v1/decide'));
    }

    public function testADecisionNamingOcrIsNotRetriedAfterATimeout(): void
    {
        foreach ([['ocr'], [Booster::ocr('What is the total?')], [['name' => 'ocr']]] as $boosters) {
            $dn = $this->client([self::timedOut(), self::timedOut(), self::timedOut()]);
            try {
                $dn->decide(questions: ['q' => truth('?')], boosters: $boosters);
                self::fail('a timeout');
            } catch (TimeoutError) {
                self::assertCount(1, $this->history);
            }
        }
        $dn = $this->client([self::timedOut(), self::timedOut(), self::timedOut()]);
        try {
            $dn->decide(questions: ['q' => truth('?')], boosters: ['age']);
            self::fail('a timeout');
        } catch (TimeoutError) {
            self::assertCount(3, $this->history, 'without ocr, retried as before');
        }
    }

    public function testItsOtherRetriesStay(): void
    {
        $busy = fn () => self::json(529, ['detail' => ['error_type' => 'overloaded_error', 'message' => 'busy']], ['retry-after-ms' => '1']);
        $dn = $this->client([$busy(), $busy(), $busy()]);
        try {
            $dn->decide(questions: ['q' => truth('?')], boosters: ['ocr']);
            self::fail('529');
        } catch (OverloadedError) {
            self::assertCount(3, $this->history);
        }
        self::assertSame(30.0, DecisionNode::OCR_TIMEOUT);
    }
}
