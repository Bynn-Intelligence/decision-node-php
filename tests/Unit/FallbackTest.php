<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\BatchRequest;

use function Bynn\DecisionNode\truth;

final class FallbackTest extends TestCase
{
    public function testFallbackIsSentOnlyWhenGiven(): void
    {
        $dn = $this->client([self::truthBody(), self::truthBody(0.5, 'check'), self::truthBody()]);
        $dn->decide(questions: ['q' => truth('?')]);
        self::assertStringNotContainsString('fallback', (string) $this->sent()->getBody());
        $dn->check(instructions: '?', fallback: false);
        self::assertFalse($this->sentBody(1)->fallback);
        $dn->decideMany([['questions' => ['q' => truth('?')], 'fallback' => true]]);
        self::assertTrue($this->sentBody(2)->fallback);
        $lines = $dn->batches->lines([new BatchRequest('a', ['q' => truth('?')], fallback: false), new BatchRequest('b', ['q' => truth('?')])])->wait();
        self::assertStringEndsWith(',"fallback":false}' . "\n", $lines[0]);
        self::assertStringNotContainsString('fallback', $lines[1]);
    }

    public function testAFallbackAnswerAndAnOrdinaryOne(): void
    {
        $body = ['model' => 'decisionnode-1.0-flash', 'answers' => ['q' => ['type' => 'truth', 'truth' => 0.5]]];
        $dn = $this->client([
            self::json(200, $body, ['x-decisionnode-fallback' => 'decisionnode-1.0-flash', 'x-decisionnode-requested-model' => 'decisionnode-1.0']),
            self::json(200, $body),
        ]);
        $d = $dn->decide(questions: ['q' => truth('?')], model: 'decisionnode-1.0');
        self::assertSame('decisionnode-1.0-flash', $d->model);
        self::assertNotNull($d->fallback);
        self::assertSame(['decisionnode-1.0-flash', 'decisionnode-1.0'], [$d->fallback->servedModel, $d->fallback->requestedModel]);
        self::assertNull($dn->decide(questions: ['q' => truth('?')])->fallback);
    }
}
