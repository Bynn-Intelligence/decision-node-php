<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\Safety;

final class SafetyTest extends TestCase
{
    public function testAbsentHeaderIsNull(): void
    {
        self::assertNull(Safety::fromHeader(null));
        self::assertNull(Safety::fromHeader('  '));
    }

    public function testCheckedScores(): void
    {
        $s = Safety::fromHeader('harm=0.031, self_harm=0.004');
        self::assertNotNull($s);
        self::assertTrue($s->checked);
        self::assertSame(0.031, $s->harm);
        self::assertSame(0.004, $s->selfHarm);
        self::assertSame([], $s->flagged);
        self::assertNull($s->reason);
    }

    public function testFlaggedAndBlockedInHeaderOrder(): void
    {
        $s = Safety::fromHeader('harm=0.97;blocked, self_harm=0.6;flagged');
        self::assertNotNull($s);
        self::assertSame(['harm', 'self_harm'], $s->flagged);
    }

    public function testUnchecked(): void
    {
        $s = Safety::fromHeader('unchecked;reason="no-model-question"');
        self::assertNotNull($s);
        self::assertFalse($s->checked);
        self::assertSame('no-model-question', $s->reason);
        self::assertNull($s->harm);
    }

    public function testUnknownCategoriesAreKept(): void
    {
        $s = Safety::fromHeader('harm=0.1, self_harm=0.2, other=0.3');
        self::assertNotNull($s);
        self::assertSame(['harm' => 0.1, 'self_harm' => 0.2, 'other' => 0.3], $s->scores);
    }
}
