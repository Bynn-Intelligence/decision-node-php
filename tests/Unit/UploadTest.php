<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Image;
use Bynn\DecisionNode\Internal\UploadCache;
use GuzzleHttp\Psr7\Response;

use function Bynn\DecisionNode\truth;

final class UploadTest extends TestCase
{
    private const DIR = __DIR__ . '/../fixtures/images/';

    private static function big(string $id = 'a', int $size = 600_000): Image
    {
        $png = (string) file_get_contents(self::DIR . '3x2.png');

        return Image::fromData($id, $png . str_repeat("\0", $size - strlen($png)));
    }

    /**
     * @param list<string> $ids
     */
    private static function presigned(array $ids, string $expiresAt = '2099-01-01T00:00:00Z'): Response
    {
        return self::json(201, ['uploads' => array_map(static fn (string $id): array => [
            'upload_id' => $id,
            'url' => 'https://storage.example.test/uploads/' . $id . '?X-Amz-Signature=abc',
            'method' => 'PUT',
            'headers' => ['Content-Type' => 'image/png', 'Content-MD5' => 'md5', 'If-None-Match' => '*'],
            'expires_at' => $expiresAt,
        ], $ids), 'expires_in' => 300]);
    }

    public function testSmallImagesGoInline(): void
    {
        $dn = $this->client([self::truthBody()]);
        $dn->decide('s', ['q' => truth('?')], [self::big('a', 200_000), self::big('b', 200_000)]);
        self::assertCount(1, $this->history);
        $body = $this->sentBody();
        self::assertSame('image/png', $body->images[0]->media_type);
        self::assertIsString($body->images[1]->data);
    }

    public function testAtTheThresholdTheImagesAreUploadedOnce(): void
    {
        $dn = $this->client([
            self::presigned(['upl_a', 'upl_b']),
            new Response(200),
            new Response(200),
            self::truthBody(),
        ], ['headers' => ['x-team' => 'ops']]);
        $a = self::big('a', 300_000);
        $b = self::big('b', 224_288);
        $dn->decide('s', ['q' => truth('?')], [$a, $b, Image::fromUrl('u', 'https://example.com/u.png')]);

        self::assertCount(4, $this->history);
        $presign = $this->sentBody(0);
        self::assertSame('/v1/uploads', $this->sent(0)->getUri()->getPath());
        self::assertSame(300_000, $presign->files[0]->byte_size);
        self::assertSame(base64_encode(md5((string) $a->data, true)), $presign->files[0]->checksum);
        self::assertSame(base64_encode(hash('sha256', (string) $a->data, true)), $presign->files[0]->sha256);

        foreach ([1, 2] as $i) {
            $put = $this->sent($i);
            self::assertSame('PUT', $put->getMethod());
            self::assertSame('storage.example.test', $put->getUri()->getHost());
            self::assertFalse($put->hasHeader('Authorization'));
            self::assertFalse($put->hasHeader('x-team'));
            self::assertFalse($put->hasHeader('User-Agent'));
            self::assertSame('*', $put->getHeaderLine('If-None-Match'));
        }

        $body = $this->sentBody(3);
        self::assertEquals((object) ['id' => 'a', 'upload_id' => 'upl_a'], $body->images[0]);
        self::assertEquals((object) ['id' => 'b', 'upload_id' => 'upl_b'], $body->images[1]);
        self::assertEquals((object) ['id' => 'u', 'url' => 'https://example.com/u.png'], $body->images[2]);
    }

    public function testTheCacheReusesAnUploadWhateverTheSize(): void
    {
        $dn = $this->client([self::presigned(['upl_a']), new Response(200), self::truthBody(), self::truthBody()]);
        $dn->decide('s', ['q' => truth('?')], [self::big()]);
        $dn->decide('s', ['q' => truth('?')], [self::big()]);
        self::assertCount(4, $this->history);
        self::assertSame('upl_a', $this->sentBody(3)->images[0]->upload_id);
    }

    public function testAStaleUploadIsUploadedAgainOnce(): void
    {
        $stale = self::json(400, ['detail' => ['error_type' => 'upload_expired', 'message' => 'expired']]);
        $dn = $this->client([
            self::presigned(['upl_a']), new Response(200), $stale,
            self::presigned(['upl_b']), new Response(200), self::truthBody(),
        ], ['maxRetries' => 0]);
        $dn->decide('s', ['q' => truth('?')], [self::big()]);
        self::assertCount(6, $this->history);
        self::assertSame('upl_b', $this->sentBody(5)->images[0]->upload_id);
    }

    public function testASecondStaleAnswerIsRaised(): void
    {
        $stale = self::json(400, ['detail' => ['error_type' => 'upload_consumed', 'message' => 'consumed']]);
        $dn = $this->client([
            self::presigned(['upl_a']), new Response(200), $stale,
            self::presigned(['upl_b']), new Response(200), $stale,
        ], ['maxRetries' => 0]);
        try {
            $dn->decide('s', ['q' => truth('?')], [self::big()]);
            self::fail('no error');
        } catch (BadRequestError $e) {
            self::assertSame('upload_consumed', $e->errorType);
        }
        self::assertCount(6, $this->history);
    }

    public function testAnUploadIdTheCallerGaveIsNotUploadedAgain(): void
    {
        $dn = $this->client([self::json(400, ['detail' => ['error_type' => 'upload_not_found', 'message' => 'not found']])], ['maxRetries' => 2]);
        $this->expectException(BadRequestError::class);
        try {
            $dn->decide('s', ['q' => truth('?')], [Image::fromUploadId('r', 'upl_x')]);
        } finally {
            self::assertCount(1, $this->history);
        }
    }

    public function testA412OnARetriedPutIsSuccess(): void
    {
        $dn = $this->client([self::presigned(['upl_a']), new Response(500), new Response(412), self::truthBody()]);
        $dn->decide('s', ['q' => truth('?')], [self::big()]);
        self::assertCount(4, $this->history);
    }

    public function testA412OnTheFirstPutIsAFailure(): void
    {
        $xml = '<?xml version="1.0"?><Error><Code>PreconditionFailed</Code></Error>';
        $dn = $this->client([self::presigned(['upl_a']), new Response(412, [], $xml)]);
        try {
            $dn->decide('s', ['q' => truth('?')], [self::big()]);
            self::fail('no error');
        } catch (DecisionNodeError $e) {
            self::assertSame(DecisionNodeError::class, get_class($e));
            self::assertSame(412, $e->status);
            self::assertSame('upload_failed', $e->errorType);
            self::assertSame('PreconditionFailed', $e->reason);
        }
    }

    public function testAPlainHttpStorageUrlIsRefusedBeforeAnyByte(): void
    {
        $presign = self::json(201, ['uploads' => [[
            'upload_id' => 'upl_a',
            'url' => 'http://storage.example.test/obj',
            'headers' => [],
            'expires_at' => '2099-01-01T00:00:00Z',
        ]]]);
        $dn = $this->client([$presign]);
        $this->expectException(DecisionNodeError::class);
        try {
            $dn->decide('s', ['q' => truth('?')], [self::big()]);
        } finally {
            self::assertCount(1, $this->history);
        }
    }

    public function testExplicitUploadReturnsTheIds(): void
    {
        $dn = $this->client([self::presigned(['upl_a']), new Response(200)]);
        $small = Image::fromPath('p', self::DIR . '3x2.png');
        self::assertSame(['upl_a', 'upl_a'], $dn->upload([$small, Image::fromPath('q', self::DIR . '3x2.png')]));
        self::assertCount(2, $this->history);
        self::assertCount(1, $this->sentBody(0)->files);
        // Cached now: no request.
        self::assertSame(['upl_a'], $dn->upload([$small]));
        self::assertCount(2, $this->history);
    }

    public function testInlineThresholdOption(): void
    {
        $dn = $this->client([self::presigned(['upl_a']), new Response(200), self::truthBody()], ['inlineThreshold' => 0]);
        $dn->decide('s', ['q' => truth('?')], [Image::fromPath('p', self::DIR . '3x2.png')]);
        self::assertCount(3, $this->history);
    }

    public function testCacheExpiryMarginAndBound(): void
    {
        $now = 1_000_000.0;
        $cache = new UploadCache(static function () use (&$now): float {
            return $now;
        }, 2);
        $cache->put('a', 'upl_a', $now + 3600);
        self::assertSame('upl_a', $cache->get('a'));
        $now += 3600 - UploadCache::MARGIN - 1;
        self::assertSame('upl_a', $cache->get('a'));
        $now += 1;
        self::assertNull($cache->get('a'));

        $cache->put('a', 'upl_a', $now + 3600);
        $cache->put('b', 'upl_b', $now + 3600);
        $cache->get('a');
        $cache->put('c', 'upl_c', $now + 3600);
        self::assertNull($cache->get('b'), 'the least recently used entry goes first');
        self::assertSame('upl_a', $cache->get('a'));
        $cache->drop(['upl_a']);
        self::assertNull($cache->get('a'));
    }
}
