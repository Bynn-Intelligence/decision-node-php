<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\BoxAnswer;
use Bynn\DecisionNode\BoxesAnswer;
use Bynn\DecisionNode\Decision;
use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\Image;
use Bynn\DecisionNode\PointAnswer;
use Bynn\DecisionNode\PointsAnswer;
use Bynn\DecisionNode\Question;
use Bynn\DecisionNode\UnknownAnswer;
use Bynn\DecisionNode\Video;
use Bynn\DecisionNode\VideoFrame;
use PHPUnit\Framework\Attributes\DataProvider;

use function Bynn\DecisionNode\box;
use function Bynn\DecisionNode\point;
use function Bynn\DecisionNode\truth;

final class PointBoxTest extends TestCase
{
    private static function belt(string $id = 'belt'): Image
    {
        return Image::fromUrl($id, 'https://files.example.com/line-3/' . $id . '.jpg');
    }

    public function testBuilderWireForms(): void
    {
        self::assertSame(['type' => 'point', 'instructions' => 'Point to the yellow package.'], point('Point to the yellow package.')->toArray());
        self::assertSame(
            ['type' => 'box', 'instructions' => 'Draw a box around the damaged package.', 'image' => 'belt', 'units' => 'normalized'],
            Question::box('Draw a box around the damaged package.', 'belt', 'normalized')->toArray(),
        );
        self::assertSame(['type' => 'point', 'instructions' => '?', 'units' => 'pixels'], point('?', units: 'pixels')->toArray());
    }

    public function testSentOnDecide(): void
    {
        $dn = $this->client([self::json(200, ['answers' => []])]);
        $dn->decide(images: [self::belt()], questions: [
            'pick' => point('Point to the yellow package closest to the robot arm.'),
            'damage' => box('Draw a box around the damaged package.', 'belt'),
            'any_damage' => truth('Is any package on the belt damaged?'),
        ]);

        $body = json_decode((string) $this->sent()->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['type' => 'point', 'instructions' => 'Point to the yellow package closest to the robot arm.'], $body['questions']['pick']);
        self::assertSame(['type' => 'box', 'instructions' => 'Draw a box around the damaged package.', 'image' => 'belt'], $body['questions']['damage']);
    }

    public function testAnswersParsed(): void
    {
        $d = Decision::fromResponse(['answers' => [
            'pick' => ['type' => 'point', 'x' => 812, 'y' => 455, 'present' => 0.97, 'confidence' => 0.91, 'image' => 'belt', 'width' => 1920, 'height' => 1080],
            'damage' => ['type' => 'box', 'x1' => 0.1563, 'y1' => 0.1111, 'x2' => 0.2708, 'y2' => 0.3796, 'present' => 0.95, 'confidence' => 0.88, 'image' => 'belt', 'width' => 1920, 'height' => 1080],
        ]], []);

        $pick = $d['pick'];
        self::assertInstanceOf(PointAnswer::class, $pick);
        self::assertSame([812, 455, 0.97, 0.91, 'belt', 1920, 1080], [$pick->x, $pick->y, $pick->present, $pick->confidence, $pick->image, $pick->width, $pick->height]);
        $damage = $d['damage'];
        self::assertInstanceOf(BoxAnswer::class, $damage);
        self::assertSame([0.1563, 0.1111, 0.2708, 0.3796, 0.95, 0.88, 'belt', 1920, 1080], [$damage->x1, $damage->y1, $damage->x2, $damage->y2, $damage->present, $damage->confidence, $damage->image, $damage->width, $damage->height]);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedAnswers(): array
    {
        $point = ['type' => 'point', 'x' => 1, 'y' => 2, 'present' => 0.5, 'confidence' => 0.5, 'image' => 'a', 'width' => 4, 'height' => 4];
        $box = ['type' => 'box', 'x1' => 1, 'y1' => 2, 'x2' => 3, 'y2' => 4, 'present' => 0.5, 'confidence' => 0.5, 'image' => 'a', 'width' => 4, 'height' => 4];
        $without = static function (array $answer, string $field): array {
            unset($answer[$field]);

            return $answer;
        };

        return [
            'point without y' => [$without($point, 'y')],
            'point with present a boolean' => [['present' => true] + $point],
            'point with image a number' => [['image' => 0] + $point],
            'point with a string coordinate' => [['x' => '1'] + $point],
            'box without height' => [$without($box, 'height')],
            'box with confidence null' => [['confidence' => null] + $box],
        ];
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('malformedAnswers')]
    public function testMalformedAnswerIsUnknown(array $answer): void
    {
        $d = Decision::fromResponse(['answers' => ['q' => $answer, 't' => ['type' => 'truth', 'truth' => 0.4]]], []);

        self::assertInstanceOf(UnknownAnswer::class, $d['q']);
        self::assertSame($answer, $d['q']->raw);
        self::assertSame(0.4, $d['t']->truth);
    }

    public function testEveryEntryInOneValidationError(): void
    {
        $dn = $this->client([]);
        try {
            $dn->decide(
                images: [self::belt('a'), self::belt('b')],
                videos: [Video::of('cam', [VideoFrame::fromBase64(0, 'QUJD', 'image/jpeg')])],
                questions: [
                    'p1' => point('?'),
                    'p2' => ['type' => 'box', 'instructions' => '?', 'image' => 7],
                    'p3' => point('?', 'cam'),
                    'p4' => box('?', 'a', 'percent'),
                    'p5' => ['type' => 'point', 'instructions' => 5, 'image' => 'b'],
                ],
            );
            self::fail('no error');
        } catch (ValidationError $e) {
            self::assertNull($e->status);
            self::assertNull($e->requestId);
            self::assertNull($e->errorType);
            $q = static fn (array $fields): \stdClass => json_decode((string) json_encode($fields), false);
            self::assertEquals([
                ['type' => 'missing', 'loc' => ['body', 'questions', 'p1', 'point', 'image'], 'msg' => 'Field required', 'input' => $q(['type' => 'point', 'instructions' => '?'])],
                ['type' => 'string_type', 'loc' => ['body', 'questions', 'p2', 'box', 'image'], 'msg' => 'Input should be a valid string', 'input' => 7],
                ['type' => 'unknown_image', 'loc' => ['body', 'questions', 'p3', 'point', 'image'], 'msg' => 'Input should be the id of an image of the request', 'input' => 'cam'],
                ['type' => 'literal_error', 'loc' => ['body', 'questions', 'p4', 'box', 'units'], 'msg' => "Input should be 'pixels' or 'normalized'", 'input' => 'percent', 'ctx' => ['expected' => "'pixels' or 'normalized'"]],
                ['type' => 'string_type', 'loc' => ['body', 'questions', 'p5', 'point', 'instructions', 'str'], 'msg' => 'Input should be a valid string', 'input' => 5],
                ['type' => 'dict_type', 'loc' => ['body', 'questions', 'p5', 'point', 'instructions', 'dict[any,any]'], 'msg' => 'Input should be a valid dictionary', 'input' => 5],
                ['type' => 'list_type', 'loc' => ['body', 'questions', 'p5', 'point', 'instructions', 'list[any]'], 'msg' => 'Input should be a valid list', 'input' => 5],
            ], $e->entries);
        }
        self::assertSame([], $this->history);
    }

    public function testImageRequired(): void
    {
        $dn = $this->client([]);
        try {
            $dn->decide('s', ['pick' => point('Point to the package.')]);
            self::fail('no error');
        } catch (ValidationError $e) {
            self::assertEquals([[
                'type' => 'image_required',
                'loc' => ['body', 'questions', 'pick', 'point'],
                'msg' => 'A point question needs an image in the request',
                'input' => (object) ['type' => 'point', 'instructions' => 'Point to the package.'],
            ]], $e->entries);
        }
    }

    public function testOneImageNeedsNoImageField(): void
    {
        $dn = $this->client([self::json(200, ['answers' => []])]);
        $dn->decide(images: [self::belt()], questions: ['pick' => point('?'), 'damage' => box('?', units: 'normalized')]);
        self::assertCount(1, $this->history);
    }

    public function testMissingInstructionsIsThePlain400(): void
    {
        $dn = $this->client([]);
        try {
            $dn->decide(images: [self::belt()], questions: ['damage' => ['type' => 'box', 'image' => 'belt']]);
            self::fail('no error');
        } catch (BadRequestError $e) {
            self::assertSame('Box question must have instructions: damage', $e->getMessage());
            self::assertNull($e->errorType);
            self::assertNull($e->status);
        }
    }

    public function testBatchLineAndCheckRequest(): void
    {
        $dn = $this->client([]);
        $dn->checkRequest('decide', ['images' => [self::belt()], 'questions' => ['pick' => point('?')]]);
        try {
            $dn->batches->create([['custom_id' => 'r1', 'questions' => ['pick' => point('?', 'belt')]]]);
            self::fail('no error');
        } catch (ValidationError $e) {
            self::assertSame('image_required', $e->entries[0]['type']);
        }
        self::assertSame([], $this->history);
    }

    public function testHeldImageLimitUnderImages(): void
    {
        $dn = $this->client([self::json(200, ['models' => [['name' => 'decisionnode-moe', 'description' => '', 'release_date' => '2026-10-08', 'images' => ['max_images' => 2]]]])]);
        $dn->models();
        try {
            $dn->decide('s', ['q' => truth('?')], [self::belt('a'), self::belt('b'), self::belt('c')]);
            self::fail('no error');
        } catch (ValidationError $e) {
            self::assertSame('List should have at most 2 items after validation, not 3', $e->entries[0]['msg']);
            self::assertSame(['field_type' => 'List', 'max_length' => 2, 'actual_length' => 3], $e->entries[0]['ctx']);
        }
        self::assertCount(1, $this->history);
    }

    public function testSeveralPlacesSentAndParsed(): void
    {
        self::assertSame(['type' => 'box', 'instructions' => 'Every car.', 'image' => 'lot', 'max' => 8], box('Every car.', 'lot', max: 8)->toArray());
        self::assertSame(['type' => 'point', 'instructions' => 'Every person.', 'max' => 4], point('Every person.', max: 4)->toArray());

        $d = Decision::fromResponse(['answers' => [
            'cars' => ['type' => 'box', 'count' => 2, 'present' => 0.99, 'complete' => 0.94, 'boxes' => [
                ['x1' => 112, 'y1' => 400, 'x2' => 388, 'y2' => 590, 'confidence' => 0.91],
                ['x1' => 402, 'y1' => 395, 'x2' => 660, 'y2' => 598, 'confidence' => 0.9],
            ], 'image' => 'lot', 'width' => 1920, 'height' => 1080],
            'people' => ['type' => 'point', 'count' => 0, 'present' => 0.04, 'complete' => 0.97, 'points' => [], 'image' => 'lot', 'width' => 1920, 'height' => 1080],
            'wrong' => ['type' => 'box', 'count' => 1, 'present' => 0.9, 'complete' => 0.9, 'points' => [['x' => 1, 'y' => 2, 'confidence' => 0.5]], 'image' => 'lot', 'width' => 1920, 'height' => 1080],
        ]], []);
        $cars = $d['cars'];
        self::assertInstanceOf(BoxesAnswer::class, $cars);
        self::assertSame([2, 0.94, 402, 0.9], [$cars->count, $cars->complete, $cars->boxes[1]['x1'], $cars->boxes[1]['confidence']]);
        $people = $d['people'];
        self::assertInstanceOf(PointsAnswer::class, $people);
        self::assertSame([0, []], [$people->count, $people->points]);
        self::assertInstanceOf(UnknownAnswer::class, $d['wrong']);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function badMax(): iterable
    {
        yield 'a fraction' => [2.5, 'int_type'];
        yield 'below 2' => [1, 'greater_than_equal'];
        yield 'above 16' => [17, 'less_than_equal'];
    }

    #[DataProvider('badMax')]
    public function testMaxCheckedLocally(int|float $max, string $type): void
    {
        $dn = $this->client([]);
        try {
            $dn->decide(images: [self::belt()], questions: ['cars' => box('Every car.', max: $max)]);
            self::fail('no error');
        } catch (ValidationError $e) {
            self::assertSame($type, $e->entries[0]['type']);
            self::assertSame(['body', 'questions', 'cars', 'box', 'max'], $e->entries[0]['loc']);
        }
        self::assertSame([], $this->history);
    }

    public function testSeveralPlacesSentWhenTheHeldModelHasNone(): void
    {
        $notSupported = ['type' => 'not_supported', 'loc' => ['body', 'questions', 'cars', 'box', 'max'], 'msg' => 'Several places are not supported by this model version', 'input' => 8];
        $dn = $this->client([
            self::json(200, ['models' => [['name' => 'decisionnode-moe', 'description' => '', 'release_date' => '2026-10-08']]]),
            self::json(422, ['detail' => [$notSupported]]),
        ]);
        $dn->models();
        try {
            $dn->decide(images: [self::belt()], questions: ['cars' => box('Every car.', max: 8)]);
            self::fail('no error');
        } catch (ValidationError $e) {
            self::assertSame(['not_supported', 'Several places are not supported by this model version', 8], [$e->entries[0]['type'], $e->entries[0]['msg'], $e->entries[0]['input']]);
            self::assertSame(422, $e->status);
        }
        self::assertCount(2, $this->history);
    }
}
