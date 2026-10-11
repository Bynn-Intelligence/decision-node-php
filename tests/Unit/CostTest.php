<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use function Bynn\DecisionNode\truth;

final class CostTest extends TestCase
{
    public function testTheCostIsReadAsSentAndAbsentIsNull(): void
    {
        $body = '{"model":"m","answers":{"q":{"type":"truth","truth":0.5}},"usage":{"input_tokens":341,"output_tokens":0,"cost":{"usd":0.036014322,"model_usd":0.000014322,"boosters_usd":0.036,"free_tier":false}}}';
        $dn = $this->client([self::json(200, $body), self::truthBody()]);
        $cost = $dn->decide(questions: ['q' => truth('?')])->usage->cost;
        self::assertNotNull($cost);
        // The whole as sent, not the sum of its parts (0.036014321999999994).
        self::assertSame([0.036014322, 0.000014322, 0.036, false], [$cost->usd, $cost->modelUsd, $cost->boostersUsd, $cost->freeTier]);
        self::assertNull($dn->decide(questions: ['q' => truth('?')])->usage->cost);
    }
}
