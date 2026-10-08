<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\Internal\ImageHeader;
use PHPUnit\Framework\Attributes\DataProvider;

final class ImageHeaderTest extends TestCase
{
    private const DIR = __DIR__ . '/../fixtures/images/';

    /**
     * @return array<string, array{string, string, int, int}>
     */
    public static function fixtures(): array
    {
        return [
            'png' => ['3x2.png', 'image/png', 3, 2],
            'jpeg baseline' => ['5x4.jpg', 'image/jpeg', 5, 4],
            'jpeg progressive' => ['7x3-progressive.jpg', 'image/jpeg', 7, 3],
            'webp lossy' => ['6x5-lossy.webp', 'image/webp', 6, 5],
            'webp lossless' => ['9x8-lossless.webp', 'image/webp', 9, 8],
            'webp extended' => ['300x200-extended.webp', 'image/webp', 300, 200],
            'png header only' => ['8000x6000-header-only.png', 'image/png', 8000, 6000],
        ];
    }

    #[DataProvider('fixtures')]
    public function testReadsTypeAndSizeFromTheHeader(string $file, string $type, int $width, int $height): void
    {
        $data = (string) file_get_contents(self::DIR . $file);
        self::assertSame($type, ImageHeader::mediaType($data));
        self::assertSame([$width, $height], ImageHeader::dimensions($data, $type));

        // Bytes after the image change nothing the header says.
        $padded = $data . str_repeat("\0", 4096);
        self::assertSame([$width, $height], ImageHeader::dimensions($padded, $type));
    }

    #[DataProvider('fixtures')]
    public function testAgreesWithPhp(string $file, string $type, int $width, int $height): void
    {
        $size = getimagesizefromstring((string) file_get_contents(self::DIR . $file));
        self::assertIsArray($size);
        self::assertSame([$width, $height, $type], [$size[0], $size[1], $size['mime']]);
    }

    public function testUnknownFormats(): void
    {
        self::assertNull(ImageHeader::mediaType((string) file_get_contents(self::DIR . 'not-an-image.gif')));
        self::assertNull(ImageHeader::mediaType(''));
        self::assertNull(ImageHeader::mediaType('RIFF1234WAVE'));
    }

    public function testTruncatedHeaders(): void
    {
        foreach (['3x2.png' => 'image/png', '5x4.jpg' => 'image/jpeg', '6x5-lossy.webp' => 'image/webp', '9x8-lossless.webp' => 'image/webp'] as $file => $type) {
            $data = (string) file_get_contents(self::DIR . $file);
            self::assertNull(ImageHeader::dimensions(substr($data, 0, 16), $type), $file);
        }
        // A JPEG whose segments never reach a frame header.
        self::assertNull(ImageHeader::dimensions("\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00", 'image/jpeg'));
        // A segment length below 2 is corrupt.
        self::assertNull(ImageHeader::dimensions("\xFF\xD8\xFF\xE0\x00\x01" . str_repeat("\0", 16), 'image/jpeg'));
    }
}
