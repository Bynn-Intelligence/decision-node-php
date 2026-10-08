<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\Exception\ServerError;
use Bynn\DecisionNode\NumberAnswer;
use Bynn\DecisionNode\Question;
use Bynn\DecisionNode\SessionClosed;
use Bynn\DecisionNode\TruthAnswer;
use Bynn\DecisionNode\UnknownAnswer;

use function Bynn\DecisionNode\choice;
use function Bynn\DecisionNode\number;
use function Bynn\DecisionNode\truth;

final class QuestionTypesTest extends TestCase
{
    public function testNumberBuilderLeavesOutWhatIsNotGiven(): void
    {
        self::assertSame(['type' => 'number', 'instructions' => 'How many?'], number('How many?')->toArray());
        self::assertSame(['type' => 'number', 'instructions' => 'Temp?', 'min' => -20.5, 'max' => 40, 'step' => 0.5], Question::number('Temp?', -20.5, 40, 0.5)->toArray());
        self::assertSame(['type' => 'number', 'instructions' => 'Cars?', 'max' => 50], number('Cars?', max: 50)->toArray());
    }

    public function testNumberHelper(): void
    {
        $dn = $this->client([self::json(200, [
            'model' => 'decisionnode-1.0',
            'answers' => ['number' => ['type' => 'number', 'number' => 7, 'expected' => 7.0, 'confidence' => 0.78, 'probabilities' => ['6' => 0.105, '7' => 0.783, '7.5' => 0.098]]],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 0],
        ])]);
        $answer = $dn->number('Three cars and four bikes.', 'How many cars?', 0, 20);
        self::assertSame(['type' => 'number', 'instructions' => 'How many cars?', 'min' => 0, 'max' => 20], json_decode(json_encode($this->sentBody()->questions->number), true));
        self::assertSame(7, $answer->number);
        self::assertSame(7.0, $answer->expected);
        self::assertSame(0.78, $answer->confidence);
        self::assertSame(0.783, $answer->probabilities['7']);
        self::assertSame(0.098, $answer->probabilities['7.5']);
    }

    public function testNumberHelperWithAMalformedAnswer(): void
    {
        $dn = $this->client([self::json(200, ['answers' => ['number' => ['type' => 'number', 'number' => 3]]])]);
        $this->expectException(ServerError::class);
        $dn->number('s', 'How many?');
    }

    public function testNoulIsReadAsTruth(): void
    {
        $dn = $this->client([self::json(200, ['answers' => [
            'a' => ['type' => 'noul', 'noul' => 0.25],
            'b' => ['type' => 'noul', 'truth' => 0.25],
            'n' => ['type' => 'number', 'number' => 1, 'expected' => 1.0, 'confidence' => 0.5, 'probabilities' => []],
        ]])]);
        $d = $dn->decide('s', ['a' => truth('?'), 'b' => truth('?'), 'n' => number('?')]);
        self::assertInstanceOf(TruthAnswer::class, $d['a']);
        self::assertSame(0.25, $d['a']->truth);
        self::assertSame('truth', $d['a']->type);
        self::assertInstanceOf(UnknownAnswer::class, $d['b']);
        // probabilities sent as a list, not an object
        self::assertInstanceOf(UnknownAnswer::class, $d['n']);
    }

    public function testNumberAnswerParsed(): void
    {
        $dn = $this->client([self::json(200, ['answers' => [
            'n' => ['type' => 'number', 'number' => -2.5, 'expected' => -2.35, 'confidence' => 0.62, 'probabilities' => ['-3' => 0.2, '-2.5' => 0.625]],
        ]])]);
        $answer = $dn->decide('s', ['n' => number('?', -5, 5, 0.5)])['n'];
        self::assertInstanceOf(NumberAnswer::class, $answer);
        self::assertSame(-2.5, $answer->number);
        self::assertSame([-3 => 0.2, '-2.5' => 0.625], $answer->probabilities);
    }

    public function testTruthIsSentAsTruth(): void
    {
        $dn = $this->client([self::truthBody(id: 't')]);
        $dn->decide('s', ['t' => truth('Escalate?'), 'c' => choice('Where?', ['billing', 'bug'])]);
        self::assertSame('/v1/decide', $this->sent()->getUri()->getPath());
        self::assertSame('truth', $this->sentBody()->questions->t->type);
    }

    public function testSessionCloseCodes(): void
    {
        foreach ([1001, 1006, 1011, 4404, 4408, 4409, 4410] as $code) {
            self::assertTrue((new SessionClosed($code))->reopen, (string) $code);
        }
        foreach ([1000, 1008, 1009, 1013, 4401, 4402, 4403] as $code) {
            self::assertFalse((new SessionClosed($code))->reopen, (string) $code);
        }
        self::assertSame(4410, (new SessionClosed(4410, 'time is up'))->closeCode);
    }
}
