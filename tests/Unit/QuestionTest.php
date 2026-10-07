<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\Question;

use function Bynn\DecisionNode\choice;
use function Bynn\DecisionNode\score;
use function Bynn\DecisionNode\truth;

final class QuestionTest extends TestCase
{
    public function testTruthWithoutCriteria(): void
    {
        self::assertSame('{"type":"truth","instructions":"Is it spam?"}', json_encode(truth('Is it spam?')));
    }

    public function testTruthWithBothSides(): void
    {
        self::assertSame(
            '{"type":"truth","instructions":"Refund?","criteria":{"true":"money back","false":"anything else"}}',
            json_encode(truth('Refund?', 'money back', 'anything else')),
        );
    }

    public function testTruthWithOneSideOrCriteriaOnly(): void
    {
        self::assertSame('{"type":"truth","instructions":"Spam?","criteria":{"true":"bulk ads"}}', json_encode(truth('Spam?', ifTrue: 'bulk ads')));
        self::assertSame('{"type":"truth","criteria":{"true":"damaged","false":"fine"}}', json_encode(truth(ifTrue: 'damaged', ifFalse: 'fine')));
    }

    public function testChoiceFromMapKeepsNamesAndOrder(): void
    {
        $q = choice('Which?', ['' => 'none', '01' => 'numeric string', ' padded ' => 'spaces', 'b' => null]);
        self::assertSame('{"type":"choice","instructions":"Which?","criteria":{"":"none","01":"numeric string"," padded ":"spaces","b":null}}', json_encode($q));
    }

    public function testChoiceFromListOfNames(): void
    {
        self::assertSame('{"type":"choice","instructions":"Currency?","criteria":{"SEK":null,"EUR":null}}', json_encode(choice('Currency?', ['SEK', 'EUR'])));
    }

    public function testChoiceWithIntegerNamesStaysAnObject(): void
    {
        $q = choice('Stars?', (object) ['0' => 'none', '1' => 'one']);
        self::assertSame('{"type":"choice","instructions":"Stars?","criteria":{"0":"none","1":"one"}}', json_encode($q));
        self::assertSame('{"type":"choice","instructions":"?","criteria":{}}', json_encode(choice('?', [])));
    }

    public function testChoiceRejectsNonScalarNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        choice('?', [['nested']]);
    }

    public function testScoreKeepsLevelsInOrder(): void
    {
        $q = score(['question' => 'How risky?'], ['none', ['what' => 'some'], ['high', 'risk']]);
        self::assertSame('{"type":"score","instructions":{"question":"How risky?"},"criteria":["none",{"what":"some"},["high","risk"]]}', json_encode($q));
    }

    public function testStaticBuildersMatchFunctions(): void
    {
        self::assertEquals(truth('a', 'b'), Question::truth('a', 'b'));
        self::assertSame(['type' => 'score', 'instructions' => 'x', 'criteria' => ['l', 'h']], Question::score('x', ['l', 'h'])->toArray());
    }
}
