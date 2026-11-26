<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\BatchResult;
use Bynn\DecisionNode\Decision;

use function Bynn\DecisionNode\truth;

final class VisionTokensTest extends TestCase
{
    private const IMAGE = 'x-decisionnode-image-tokens';
    private const VIDEO = 'x-decisionnode-video-tokens';
    private const BODY = ['model' => 'decisionnode-flash', 'answers' => [], 'usage' => ['input_tokens' => 1300, 'output_tokens' => 0]];

    /**
     * @param array<string, string> $headers
     *
     * @return array{?int, ?int}
     */
    private static function read(array $headers): array
    {
        $d = Decision::fromResponse(self::BODY, $headers);
        // The shares never change the input count and never reach raw.
        self::assertSame(1300, $d->usage->inputTokens);
        self::assertSame(self::BODY, $d->raw);

        return [$d->usage->imageTokens, $d->usage->videoTokens];
    }

    private static function one(string $name, string $value): ?int
    {
        [$image, $video] = self::read([$name => $value]);
        if ($name === self::IMAGE) {
            self::assertNull($video, "{$name}={$value}");

            return $image;
        }
        self::assertNull($image, "{$name}={$value}");

        return $video;
    }

    public function testBothHeadersAreRead(): void
    {
        self::assertSame([1024, 256], self::read([self::IMAGE => '1024', self::VIDEO => '256']));
    }

    public function testBothAreNullWithoutTheHeaders(): void
    {
        self::assertSame([null, null], self::read([]));
    }

    public function testAWholeNumberFromZeroToTheLimitIsReadWithSpacesTrimmed(): void
    {
        foreach ([self::IMAGE, self::VIDEO] as $name) {
            self::assertSame(512, self::one($name, '512'), $name);
            self::assertSame(0, self::one($name, '0'), $name);
            self::assertSame(77, self::one($name, '  77 '), $name);
            self::assertSame(7, self::one($name, '000000000007'), $name);
            self::assertSame(2147483647, self::one($name, '2147483647'), $name);
        }
    }

    public function testAnEmptyNegativeNonNumberOrTooLargeValueIsNull(): void
    {
        $bad = ['', '   ', '-1', '-0', '+5', '12.0', '1e3', 'abc', '12 3', '0x10', "12\n3", '2147483648', '9999999999', '99999999999999999999999'];
        foreach ([self::IMAGE, self::VIDEO] as $name) {
            foreach ($bad as $value) {
                self::assertNull(self::one($name, $value), "{$name}=" . json_encode($value));
            }
        }
    }

    public function testDecideAndItsAsyncFormReadThemFromTheResponse(): void
    {
        $reply = static fn () => self::json(200, [
            'model' => 'decisionnode-flash',
            'answers' => ['q' => ['type' => 'truth', 'truth' => 0.9]],
            'usage' => ['input_tokens' => 1400, 'output_tokens' => 0],
        ], ['x-request-id' => 'req_9', 'X-DecisionNode-Image-Tokens' => '1024', self::VIDEO => '300']);
        $dn = $this->client([$reply(), $reply()]);
        foreach ([$dn->decide(questions: ['q' => truth('?')]), $dn->decideAsync(questions: ['q' => truth('?')])->wait()] as $d) {
            self::assertSame('req_9', $d->requestId);
            self::assertSame(1400, $d->usage->inputTokens);
            self::assertSame([1024, 300], [$d->usage->imageTokens, $d->usage->videoTokens]);
            self::assertArrayNotHasKey('image_tokens', $d->raw['usage']);
        }
    }

    public function testABatchLineWithoutTheHeadersReadsNullForBoth(): void
    {
        $r = BatchResult::fromArray(['custom_id' => 'a', 'status' => 200, 'body' => self::BODY, 'headers' => ['x-request-id' => 'req_1']]);
        self::assertNotNull($r->decision);
        self::assertSame('req_1', $r->decision->requestId);
        self::assertSame([null, null], [$r->decision->usage->imageTokens, $r->decision->usage->videoTokens]);
    }
}
