<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\Image;
use Bynn\DecisionNode\Internal\LocalChecks;
use PHPUnit\Framework\Attributes\DataProvider;

use function Bynn\DecisionNode\choice;
use function Bynn\DecisionNode\number;
use function Bynn\DecisionNode\score;
use function Bynn\DecisionNode\truth;

final class LocalChecksTest extends TestCase
{
    private const DIR = __DIR__ . '/../fixtures/images/';

    /**
     * @return array<string, array{\Closure(): array{array<string, mixed>, list<Image>}, class-string<DecisionNodeError>}>
     */
    public static function faults(): array
    {
        $png = static fn (string $id = 'p'): Image => Image::fromPath($id, self::DIR . '3x2.png');
        $q = ['q' => truth('?')];

        return [
            'gif' => [static fn () => [$q, [Image::fromPath('g', self::DIR . 'not-an-image.gif')]], BadRequestError::class],
            'declared type differs' => [static fn () => [$q, [Image::fromData('p', (string) file_get_contents(self::DIR . '3x2.png'), 'image/jpeg')]], BadRequestError::class],
            'over 10 MB' => [static fn () => [$q, [Image::fromData('b', (string) file_get_contents(self::DIR . '3x2.png') . str_repeat("\0", 10_485_760))]], BadRequestError::class],
            'over 40 megapixels' => [static fn () => [$q, [Image::fromPath('h', self::DIR . '8000x6000-header-only.png')]], BadRequestError::class],
            'five images' => [static fn () => [$q, [$png('a'), $png('b'), $png('c'), $png('d'), Image::fromUrl('e', 'https://example.com/e.png')]], BadRequestError::class],
            'duplicate ids' => [static fn () => [$q, [$png('a'), $png('a')]], BadRequestError::class],
            'plain http url' => [static fn () => [$q, [Image::fromUrl('u', 'http://example.com/a.png')]], BadRequestError::class],
            'url too long' => [static fn () => [$q, [Image::fromUrl('u', 'https://example.com/' . str_repeat('a', 2030))]], BadRequestError::class],
            'choice without options' => [static fn () => [['q' => choice('?', [])], []], BadRequestError::class],
            'choice with 256 options' => [static fn () => [['q' => choice('?', array_map('strval', range(1, 256)))], []], BadRequestError::class],
            'score with 11 levels' => [static fn () => [['q' => score('?', array_map('strval', range(0, 10)))], []], BadRequestError::class],
            'score without levels' => [static fn () => [['q' => score('?', [])], []], ValidationError::class],
            'truth without text' => [static fn () => [['q' => truth()], []], BadRequestError::class],
            'truth wire map without text' => [static fn () => [['q' => ['type' => 'truth']], []], BadRequestError::class],
            'number without instructions' => [static fn () => [['n' => number(min: 0, max: 10)], []], BadRequestError::class],
            'number step zero' => [static fn () => [['n' => number('?', 0, 10, 0)], []], ValidationError::class],
            'number min above max' => [static fn () => [['n' => number('?', 60, 50)], []], ValidationError::class],
            'number grid over 4096' => [static fn () => [['n' => number('?', 0, 1000, 0.1)], []], ValidationError::class],
            'number grid over 4096 by defaults' => [static fn () => [['n' => number('?', max: 4096)], []], ValidationError::class],
        ];
    }

    /**
     * @param \Closure(): array{array<string, mixed>, list<Image>} $make
     * @param class-string<DecisionNodeError>                      $class
     */
    #[DataProvider('faults')]
    public function testRefusedBeforeAnythingIsSent(\Closure $make, string $class): void
    {
        [$questions, $images] = $make();
        $dn = $this->client([]);
        try {
            $dn->decide('s', $questions, $images);
            self::fail('no error');
        } catch (DecisionNodeError $e) {
            self::assertSame($class, get_class($e));
            self::assertNull($e->status);
            self::assertNull($e->requestId);
            if ($e instanceof ValidationError) {
                self::assertNull($e->errorType);
                self::assertNotEmpty($e->entries);
            } else {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame([], $this->history);
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function urls(): array
    {
        return [
            'plain http' => ['http://example.com/a.png', 'api_usage_error'],
            'too long' => ['https://example.com/' . str_repeat('a', 2030), 'image_url_refused'],
            'user information' => ['https://user:pw@example.com/a.png', 'image_url_refused'],
            'another port' => ['https://example.com:8443/a.png', 'image_url_refused'],
            'loopback' => ['https://127.0.0.1/a.png', 'image_url_refused'],
            'private' => ['https://10.1.2.3/a.png', 'image_url_refused'],
            'metadata' => ['https://169.254.169.254/latest', 'image_url_refused'],
            'ipv6 loopback' => ['https://[::1]/a.png', 'image_url_refused'],
            'ipv4 mapped' => ['https://[::ffff:192.168.1.1]/a.png', 'image_url_refused'],
            'nat64' => ['https://[64:ff9b::a00:1]/a.png', 'image_url_refused'],
            'public address' => ['https://93.184.216.34/a.png', null],
            'port 443' => ['https://example.com:443/a.png', null],
            'a name' => ['https://localhost.example.com/a.png', null],
        ];
    }

    #[DataProvider('urls')]
    public function testUrlRules(string $url, ?string $errorType): void
    {
        try {
            LocalChecks::url('u', $url);
            self::assertNull($errorType, 'not refused');
        } catch (BadRequestError $e) {
            self::assertSame($errorType, $e->errorType);
        }
    }

    public function testErrorTypesAreTheServers(): void
    {
        $dn = $this->client([]);
        $cases = [
            [['q' => choice('?', [])], null, 'Choice question must have at least one choice: q'],
            [['q' => truth()], null, 'Truth question must have criteria or instructions: q'],
            [['q' => ['type' => 'opinion', 'instructions' => '?']], 'api_usage_error', 'Invalid request.'],
        ];
        foreach ($cases as [$questions, $type, $message]) {
            try {
                $dn->decide('s', $questions);
                self::fail('no error');
            } catch (BadRequestError $e) {
                self::assertSame($type, $e->errorType);
                self::assertSame($message, $e->getMessage());
            }
        }
        try {
            $dn->systemone('s', ['n' => number('How many?')]);
            self::fail('no error');
        } catch (BadRequestError $e) {
            self::assertSame('api_usage_error', $e->errorType);
        }
        try {
            $dn->decide(['deep' => self::nest(128)], ['q' => truth('?')]);
            self::fail('no error');
        } catch (BadRequestError $e) {
            self::assertSame('Invalid request.', $e->getMessage());
        }
        try {
            $dn->decide('s', ['n' => ['type' => 'number', 'instructions' => '?', 'min' => '5']]);
            self::fail('no error');
        } catch (ValidationError $e) {
            self::assertSame([['type' => 'float_type', 'loc' => ['body', 'questions', 'n', 'number', 'min'], 'msg' => 'Input should be a valid number', 'input' => '5']], $e->entries);
        }
        try {
            $dn->batches->list(limit: 101);
            self::fail('no error');
        } catch (BadRequestError $e) {
            self::assertSame('api_usage_error', $e->errorType);
        }
        self::assertSame([], $this->history);
    }

    public function testNesting127InTheStatePasses(): void
    {
        $dn = $this->client([self::truthBody(0.5, 'q')]);
        $dn->decide(self::nest(126), ['q' => truth('?')]);
        self::assertCount(1, $this->history);
    }

    /**
     * A value inside `$depth` arrays.
     *
     * @return list<mixed>
     */
    private static function nest(int $depth): array
    {
        $value = ['s'];
        for ($i = 1; $i < $depth; ++$i) {
            $value = [$value];
        }

        return $value;
    }

    public function testGridSizeInExactDecimals(): void
    {
        self::assertSame(256, LocalChecks::gridSize(0, 255, 1));
        self::assertSame(4096, LocalChecks::gridSize(0, 409.5, 0.1));
        self::assertSame(4097, LocalChecks::gridSize(0, 409.6, 0.1));
        self::assertSame(11, LocalChecks::gridSize(-0.5, 0.5, 0.1));
        self::assertSame(3, LocalChecks::gridSize(0.1, 0.3, 0.1));
        self::assertSame(1, LocalChecks::gridSize(7, 7, 1));
    }

    public function testValidRequestsPass(): void
    {
        $dn = $this->client([self::truthBody(0.5, 'q')]);
        $dn->decide('s', [
            'q' => truth(null, 'yes'),
            'c' => choice('?', array_map('strval', range(1, 255))),
            's' => score('?', array_map('strval', range(1, 10))),
            'n' => number('?', 0, 409.5, 0.1),
        ], [
            Image::fromPath('a', self::DIR . '5x4.jpg'),
            Image::fromPath('b', self::DIR . '6x5-lossy.webp'),
            Image::fromUrl('c', 'https://example.com/' . str_repeat('a', 2028)),
            Image::fromUploadId('d', 'upl_0123'),
        ]);
        self::assertCount(1, $this->history);
    }

    public function testDecideManyChecksEveryRequestFirst(): void
    {
        $dn = $this->client([]);
        try {
            $dn->decideMany([
                ['state' => 'a', 'questions' => ['q' => truth('?')]],
                ['state' => 'b', 'questions' => ['q' => truth('?')], 'images' => [Image::fromData('x', 'GIF89a')]],
            ]);
            self::fail('no error');
        } catch (BadRequestError) {
        }
        self::assertSame([], $this->history);
    }

    public function testRawBodiesAreNotChecked(): void
    {
        $dn = $this->client([self::json(400, ['detail' => 'Too many score levels. Must have at most 10 levels.'])], ['maxRetries' => 0]);
        $this->expectException(BadRequestError::class);
        try {
            $dn->decideRaw(['questions' => ['q' => ['type' => 'score', 'criteria' => range(0, 10)]]]);
        } finally {
            self::assertCount(1, $this->history);
        }
    }
}
