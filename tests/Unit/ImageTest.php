<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Image;
use GuzzleHttp\Psr7\Utils;

use function Bynn\DecisionNode\truth;

final class ImageTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1A\n\0\0\0\x0DIHDR";
    private const JPEG = "\xFF\xD8\xFF\xE0\0\x10JFIF";
    private const WEBP = "RIFF\x24\0\0\0WEBPVP8 ";

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testSniff(): void
    {
        self::assertSame('image/png', Image::sniff(self::PNG));
        self::assertSame('image/jpeg', Image::sniff(self::JPEG));
        self::assertSame('image/webp', Image::sniff(self::WEBP));
        self::assertNull(Image::sniff('GIF89a'));
    }

    public function testFromPathReadsAndEncodes(): void
    {
        foreach (['png' => self::PNG, 'jpg' => self::JPEG, 'webp' => self::WEBP] as $ext => $bytes) {
            $path = $this->file($bytes);
            $image = Image::fromPath('img', $path);
            self::assertSame(['id' => 'img', 'media_type' => Image::sniff($bytes), 'data' => base64_encode($bytes)], $image->toArray(), $ext);
        }
    }

    public function testMediaTypeComesFromBytesNotTheFileName(): void
    {
        $path = $this->file(self::PNG, '.jpg');
        self::assertSame('image/png', Image::fromPath('x', $path)->mediaType);
    }

    public function testUnreadableFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Image::fromPath('x', '/nonexistent/receipt.png');
    }

    public function testNotAnImageIsRefusedWhenSentNotWhenMade(): void
    {
        $image = Image::fromPath('x', $this->file('plain text'));
        self::assertNull($image->mediaType);

        $dn = $this->client([]);
        try {
            $dn->decide('', ['q' => truth('?')], [$image]);
            self::fail('no error');
        } catch (BadRequestError $e) {
            self::assertNull($e->status);
            self::assertNull($e->requestId);
            self::assertSame('api_usage_error', $e->errorType);
        }
        self::assertSame([], $this->history);
    }

    public function testFromBase64AndStream(): void
    {
        $png = (string) file_get_contents(__DIR__ . '/../fixtures/images/3x2.png');
        self::assertSame($png, Image::fromBase64('a', base64_encode($png))->data);
        self::assertSame('image/png', Image::fromBase64('a', base64_encode($png))->mediaType);

        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $png);
        rewind($stream);
        self::assertSame($png, Image::fromStream('s', $stream)->data);
        self::assertSame($png, Image::fromStream('p', Utils::streamFor($png))->data);
    }

    public function testBadBase64(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Image::fromBase64('a', 'not base64!');
    }

    public function testFromUploadId(): void
    {
        self::assertSame(['id' => 'r', 'upload_id' => 'upl_1'], Image::fromUploadId('r', 'upl_1')->toArray());
        self::assertSame(['id' => 'r', 'upload_id' => 'upl_1', 'media_type' => 'image/png'], Image::fromUploadId('r', 'upl_1', 'image/png')->toArray());
    }

    public function testFromDataAndUrl(): void
    {
        self::assertSame(['id' => 'a', 'media_type' => 'image/png', 'data' => base64_encode('xyz')], Image::fromData('a', 'xyz', 'image/png')->toArray());
        self::assertSame('image/jpeg', Image::fromData('a', self::JPEG)->mediaType);
        self::assertSame(['id' => 'u', 'url' => 'https://example.com/a.jpg'], Image::fromUrl('u', 'https://example.com/a.jpg')->toArray());
    }

    public function testBytesOfAnUnknownFormatWithoutMediaType(): void
    {
        $this->expectException(BadRequestError::class);
        $this->client([])->decide('', ['q' => truth('?')], [Image::fromData('x', 'GIF89a')]);
    }

    public function testExactlyOneSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Image('x', data: 'a', url: 'https://example.com/a.jpg');
    }

    private function file(string $bytes, string $suffix = ''): string
    {
        $path = sys_get_temp_dir() . '/dn-' . bin2hex(random_bytes(6)) . $suffix;
        file_put_contents($path, $bytes);
        $this->files[] = $path;

        return $path;
    }
}
