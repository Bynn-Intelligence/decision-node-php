<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\BatchRequest;
use Bynn\DecisionNode\Decision;
use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Frame;
use Bynn\DecisionNode\Image;
use Bynn\DecisionNode\Video;
use PHPUnit\Framework\Attributes\DataProvider;

use function Bynn\DecisionNode\truth;

final class VideoTest extends TestCase
{
    private const DIR = __DIR__ . '/../fixtures/images/';

    private static function jpeg(): string
    {
        return (string) file_get_contents(self::DIR . '5x4.jpg');
    }

    public function testVideoWireForm(): void
    {
        $jpeg = self::jpeg();
        $dn = $this->client([self::truthBody()]);
        $dn->decide('Dock camera 4.', ['q' => truth('Does a person fall?')], videos: [
            Video::of('dock', [Frame::fromData(30.0, $jpeg), Frame::fromBase64(30.5, 'QUJD', 'image/png')], fps: 2, end: 31),
            ['id' => 'gate', 'frames' => [['t' => 0, 'media_type' => 'image/webp', 'data' => 'UklG']]],
        ]);

        $body = json_decode((string) $this->sent()->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('images', $body);
        self::assertSame([
            ['id' => 'dock', 'fps' => 2, 'end' => 31, 'frames' => [
                ['t' => 30.0, 'media_type' => 'image/jpeg', 'data' => base64_encode($jpeg)],
                ['t' => 30.5, 'media_type' => 'image/png', 'data' => 'QUJD'],
            ]],
            ['id' => 'gate', 'frames' => [['t' => 0, 'media_type' => 'image/webp', 'data' => 'UklG']]],
        ], $body['videos']);
        self::assertSame(['model', 'state', 'questions', 'videos'], array_keys($body));
        self::assertStringContainsString('"t":30.0', (string) $this->sent()->getBody());
    }

    public function testNoVideosLeavesTheFieldOut(): void
    {
        $dn = $this->client([self::truthBody()]);
        $dn->decide('s', ['q' => truth('?')], videos: []);

        self::assertFalse(property_exists($this->sentBody(), 'videos'));
    }

    public function testFramesAreNeverUploaded(): void
    {
        // An inline threshold of 0 uploads every local image; frames still go inline.
        $dn = $this->client([self::truthBody()], ['inlineThreshold' => 0]);
        $dn->decide('s', ['q' => truth('?')], videos: [Video::of('v', [Frame::fromData(0, self::jpeg())])]);

        self::assertCount(1, $this->history);
        self::assertSame('/v1/decide', $this->sent()->getUri()->getPath());
    }

    /**
     * @return array<string, array{list<mixed>, list<Image>, string}>
     */
    public static function videoFaults(): array
    {
        $f = static fn (int|float $t, string $type = 'image/jpeg'): array => ['t' => $t, 'media_type' => $type, 'data' => 'QUJD'];
        $v = static fn (string $id, array $frames, array $more = []): array => ['id' => $id, 'frames' => $frames] + $more;
        $one = [$f(0)];

        return [
            'five videos' => [array_map(static fn (int $i): array => $v('v' . $i, $one), range(1, 5)), [], 'Too many videos: 5, the limit is 4.'],
            'no id' => [[$v('a', $one), ['frames' => $one]], [], 'Video 1 needs an id.'],
            'empty id' => [[$v('', $one)], [], 'Video 0 needs an id.'],
            'id of an image' => [[$v('dock', $one)], [Image::fromUrl('dock', 'https://example.com/d.jpg')], "Video id 'dock' is used twice (ids are unique across images and videos)."],
            'id twice' => [[$v('dock', $one), $v('dock', $one)], [], "Video id 'dock' is used twice (ids are unique across images and videos)."],
            'id with a quote' => [[$v("it's", $one), $v("it's", $one)], [], "Video id \"it's\" is used twice (ids are unique across images and videos)."],
            'no frames' => [[$v('dock', [])], [], "Video 'dock': frames must be a non-empty list of frames."],
            'frames not a list' => [[['id' => 'dock', 'frames' => ['a' => $f(0)]]], [], "Video 'dock': frames must be a non-empty list of frames."],
            'frame not an object' => [[$v('dock', [$f(0), 'x'])], [], "Video 'dock': frame 1 must be an object with t, media_type and data."],
            'negative t' => [[$v('dock', [$f(-1)])], [], "Video 'dock': frame 0: t must be a number of seconds, at least 0."],
            't not a number' => [[$v('dock', [['t' => '1', 'media_type' => 'image/jpeg', 'data' => 'QUJD']])], [], "Video 'dock': frame 0: t must be a number of seconds, at least 0."],
            't infinite' => [[$v('dock', [$f(INF)])], [], "Video 'dock': frame 0: t must be a number of seconds, at least 0."],
            't not increasing' => [[$v('dock', [$f(1.5), $f(1.5)])], [], "Video 'dock': frame 1: t must be larger than the frame before it (1.5)."],
            't going back' => [[$v('dock', [$f(4), $f(2)])], [], "Video 'dock': frame 1: t must be larger than the frame before it (4)."],
            'data not text' => [[$v('dock', [['t' => 0, 'media_type' => 'image/jpeg', 'data' => 7]])], [], "Video 'dock': frame 0: data must be base64 text."],
            'gif frame' => [[$v('dock', [$f(0, 'image/gif')])], [], "Video 'dock': frame 0: media_type must be one of image/jpeg, image/png, image/webp."],
            'fps zero' => [[$v('dock', $one, ['fps' => 0])], [], "Video 'dock': fps must be a number above 0 and at most 4."],
            'fps five' => [[$v('dock', $one, ['fps' => 5])], [], "Video 'dock': fps must be a number above 0 and at most 4."],
            'negative start' => [[$v('dock', $one, ['start' => -1])], [], "Video 'dock': start must be a number of seconds, at least 0."],
            'end not a number' => [[$v('dock', $one, ['end' => 'x'])], [], "Video 'dock': end must be a number of seconds, at least 0."],
            'end zero alone' => [[$v('dock', $one, ['end' => 0])], [], "Video 'dock': end must be after start."],
            'end before start' => [[$v('dock', $one, ['start' => 5, 'end' => 2])], [], "Video 'dock': end must be after start."],
        ];
    }

    /**
     * @param list<mixed> $videos
     * @param list<Image> $images
     */
    #[DataProvider('videoFaults')]
    public function testVideoRefusedBeforeAnythingIsSent(array $videos, array $images, string $message): void
    {
        $dn = $this->client([]);
        try {
            $dn->decide('s', ['q' => truth('?')], $images, $videos);
            self::fail('no error');
        } catch (BadRequestError $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame('api_usage_error', $e->errorType);
            self::assertNull($e->status);
            self::assertNull($e->requestId);
        }
        self::assertSame([], $this->history);
    }

    /**
     * @return array<string, array{int|float, string}>
     */
    public static function numbersInMessages(): array
    {
        return [
            'one and a half' => [1.5, '1.5'],
            'whole' => [4, '4'],
            'whole as a float' => [4.0, '4'],
            'a quarter' => [0.25, '0.25'],
            'a million' => [1000000, '1e+06'],
            'seven digits' => [1234567.5, '1.23457e+06'],
            'a hundred thousandth' => [0.00001, '1e-05'],
            'six digits' => [1000.25, '1000.25'],
            'rounded up to a million' => [999999.5, '1e+06'],
            'zero' => [0, '0'],
        ];
    }

    #[DataProvider('numbersInMessages')]
    public function testNumbersInMessagesAreWrittenAsTheServerWritesThem(int|float $t, string $written): void
    {
        $dn = $this->client([]);
        $this->expectExceptionMessage(sprintf("Video 'dock': frame 1: t must be larger than the frame before it (%s).", $written));
        $dn->decide('s', ['q' => truth('?')], videos: [Video::of('dock', [Frame::fromData($t, self::jpeg()), Frame::fromData($t, self::jpeg())])]);
    }

    public function testTypedFramesAreCheckedToo(): void
    {
        $dn = $this->client([]);
        $video = Video::of('dock', [Frame::fromData(0, 'GIF89a not a frame')]);
        $this->expectExceptionMessage("Video 'dock': frame 0: media_type must be one of image/jpeg, image/png, image/webp.");
        $dn->decide('s', ['q' => truth('?')], videos: [$video]);
    }

    public function testFrameBytesAndCountsAreLeftToTheServer(): void
    {
        // A frame whose bytes are not its media type, and 65 frames: sent as they are.
        $frames = [Frame::fromBase64(0, 'bm90IGFuIGltYWdl', 'image/jpeg')];
        for ($i = 1; $i < 65; ++$i) {
            $frames[] = Frame::fromData($i / 2, self::jpeg());
        }
        $dn = $this->client([self::truthBody()]);
        $dn->decide('s', ['q' => truth('?')], videos: [Video::of('dock', $frames)]);

        self::assertCount(65, $this->sentBody()->videos[0]->frames);
    }

    public function testFrameNeedsExactlyOneSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Frame(0);
    }

    public function testBase64FrameWithNoMediaTypeIsRefusedBeforeSending(): void
    {
        $dn = $this->client([]);
        $this->expectExceptionMessage("Video 'dock': frame 0: media_type must be one of image/jpeg, image/png, image/webp.");
        $dn->decide('s', ['q' => truth('?')], videos: [Video::of('dock', [new Frame(0, base64: 'QUJD')])]);
    }

    public function testFrameFromPath(): void
    {
        $frame = Frame::fromPath(1.5, self::DIR . '5x4.jpg');
        self::assertSame('image/jpeg', $frame->mediaType);
        self::assertSame(['t' => 1.5, 'media_type' => 'image/jpeg', 'data' => base64_encode(self::jpeg())], $frame->toArray());
    }

    public function testSixteenImagesGoInlineAndTheSeventeenthIsRefused(): void
    {
        $png = (string) file_get_contents(self::DIR . '3x2.png');
        $images = array_map(static fn (int $i): Image => Image::fromData('i' . $i, $png), range(1, 16));
        $dn = $this->client([self::truthBody()]);
        $dn->decide('s', ['q' => truth('?')], $images);
        self::assertCount(16, $this->sentBody()->images);

        try {
            $dn->decide('s', ['q' => truth('?')], [...$images, Image::fromUrl('i17', 'https://example.com/17.png')]);
            self::fail('no error');
        } catch (BadRequestError $e) {
            self::assertSame('Too many images: 17, the limit is 16.', $e->getMessage());
            self::assertSame('api_usage_error', $e->errorType);
            self::assertNull($e->status);
        }
        self::assertCount(1, $this->history);
    }

    public function testSixteenImagesUploadInOnePresign(): void
    {
        $png = (string) file_get_contents(self::DIR . '3x2.png');
        $images = [];
        $uploads = [];
        foreach (range(1, 16) as $i) {
            $images[] = Image::fromData('i' . $i, $png . str_repeat("\0", $i));
            $uploads[] = ['upload_id' => 'upl_' . $i, 'url' => 'https://storage.example.test/' . $i, 'headers' => [], 'expires_at' => '2099-01-01T00:00:00Z'];
        }
        $dn = $this->client(
            [self::json(201, ['uploads' => $uploads, 'expires_in' => 300]), ...array_fill(0, 16, self::json(200, [])), self::truthBody()],
            ['inlineThreshold' => 0],
        );
        $dn->decide('s', ['q' => truth('?')], $images);

        self::assertCount(18, $this->history);
        self::assertSame('/v1/uploads', $this->sent(0)->getUri()->getPath());
        self::assertCount(16, $this->sentBody(0)->files);
        self::assertCount(16, $this->sentBody(17)->images);
    }

    public function testVideoUsageAndWarnings(): void
    {
        $dn = $this->client([self::json(200, [
            'model' => 'decisionnode-1.0',
            'answers' => ['q' => ['type' => 'truth', 'truth' => 0.5]],
            'usage' => ['input_tokens' => 1300, 'output_tokens' => 0, 'video_frames' => 20, 'video_seconds' => 10.25],
            'warnings' => ["Video 'dock' was read at 1 frame a second."],
        ])]);
        $d = $dn->decide('s', ['q' => truth('?')], videos: [Video::of('dock', [Frame::fromData(0, self::jpeg())])]);

        self::assertSame(20, $d->usage->videoFrames);
        self::assertSame(10.25, $d->usage->videoSeconds);
        self::assertSame(["Video 'dock' was read at 1 frame a second."], $d->warnings);
    }

    public function testNoVideoUsageIsNull(): void
    {
        $d = Decision::fromResponse(['answers' => [], 'usage' => ['input_tokens' => 3, 'output_tokens' => 0]], []);

        self::assertNull($d->usage->videoFrames);
        self::assertNull($d->usage->videoSeconds);
        self::assertSame([], $d->warnings);
    }

    public function testHelpersAndSystemoneTakeVideos(): void
    {
        $video = Video::of('dock', [Frame::fromData(0, self::jpeg())]);
        $dn = $this->client([self::truthBody(id: 'check'), self::json(200, ['answers' => ['q' => ['type' => 'noul', 'noul' => 0.2]]])]);
        $dn->check('s', 'Does a person fall?', videos: [$video]);
        $dn->systemone('s', ['q' => truth('?')], videos: [$video]);

        self::assertSame('dock', $this->sentBody(0)->videos[0]->id);
        self::assertSame('/v1/systemone', $this->sent(1)->getUri()->getPath());
        self::assertSame('dock', $this->sentBody(1)->videos[0]->id);
    }

    public function testBatchLineCarriesVideos(): void
    {
        $dn = $this->client([self::json(200, ['id' => 'batch_1', 'status' => 'draft'])]);
        $dn->batches->create([new BatchRequest('r1', ['q' => truth('?')], 's', videos: [Video::of('dock', [Frame::fromBase64(0, 'QUJD', 'image/jpeg')])])]);

        $line = json_decode(trim((string) $this->sent()->getBody()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['custom_id', 'model', 'state', 'questions', 'videos'], array_keys($line));
        self::assertSame([['id' => 'dock', 'frames' => [['t' => 0, 'media_type' => 'image/jpeg', 'data' => 'QUJD']]]], $line['videos']);
    }

    public function testBatchLineVideoIsChecked(): void
    {
        $dn = $this->client([]);
        $this->expectExceptionMessage("Video 'dock': fps must be a number above 0 and at most 4.");
        $dn->batches->create([['custom_id' => 'r1', 'questions' => ['q' => truth('?')], 'videos' => [['id' => 'dock', 'fps' => 8, 'frames' => [['t' => 0, 'media_type' => 'image/jpeg', 'data' => 'QUJD']]]]]]);
    }

    public function testCheckRequestTakesVideos(): void
    {
        $dn = $this->client([]);
        $dn->checkRequest('decide', ['questions' => ['q' => truth('?')], 'videos' => [Video::of('dock', [Frame::fromData(0, self::jpeg())])]]);
        try {
            $dn->checkRequest('decide', ['questions' => ['q' => truth('?')], 'videos' => [Video::of('dock', [Frame::fromData(2, self::jpeg()), Frame::fromData(1, self::jpeg())])]]);
            self::fail('no error');
        } catch (BadRequestError $e) {
            self::assertSame("Video 'dock': frame 1: t must be larger than the frame before it (2).", $e->getMessage());
        }
        self::assertSame([], $this->history);
    }

    public function testDecideManyChecksEveryVideoFirst(): void
    {
        $dn = $this->client([]);
        $this->expectExceptionMessage('Too many videos: 5, the limit is 4.');
        $ok = Video::of('v', [Frame::fromData(0, self::jpeg())]);
        $dn->decideMany([
            ['questions' => ['q' => truth('?')], 'videos' => [$ok]],
            ['questions' => ['q' => truth('?')], 'videos' => array_map(static fn (int $i): Video => Video::of('v' . $i, [Frame::fromData(0, self::jpeg())]), range(1, 5))],
        ]);
    }
}
