<?php

declare(strict_types = 1);

namespace Northrook\Core\Tests\Integration;

use Intervention\Image\Encoders\{JpegEncoder, PngEncoder, WebpEncoder};
use LogicException;
use Northrook\Core\Image;
use Northrook\Core\Image\Blurhash;
use Northrook\Core\Image\Driver;
use Northrook\Core\Tests\Support\PixelMap;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('gd')]
final class ImageTest extends TestCase
{
    protected function tearDown(): void
    {
        Image::setPixelMapClamp(128);
    }

    public function testCreateAndGetPixelMap(): void
    {
        $image = Image::create(16, 9);
        $map = Image::getPixelMap($image, 8);

        self::assertNotEmpty($map);
        self::assertCount(8, $map);
        self::assertCount(14, $map[0]);
    }

    public function testGetPixelMapClampsResolution(): void
    {
        $image = Image::create(4, 4);

        $low = Image::getPixelMap($image, 1);
        $high = Image::getPixelMap($image, 999);

        self::assertCount(4, $low);
        self::assertCount(4, $high);
    }

    public function testSetPixelMapClamp(): void
    {
        Image::setPixelMapClamp(8);
        $image = Image::create(16, 16);
        $map = Image::getPixelMap($image, 64);

        self::assertLessThanOrEqual(8, \count($map));
        self::assertLessThanOrEqual(8, \count($map[0]));
    }

    public function testMimeTypeFromFixture(): void
    {
        self::assertSame('image/png', Image::mimeType(self::fixturePath('square.png')));
    }

    public function testMimeTypeThrowsForMissingFile(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unable to get image mime type');

        Image::mimeType('/path/does/not/exist.png');
    }

    public function testDriverDetection(): void
    {
        $driver = Image::driver();

        self::assertContains($driver, [Driver::GD, Driver::IMAGICK]);
        self::assertSame($driver === Driver::GD, Image::driver(Driver::GD));
        self::assertSame($driver === Driver::IMAGICK, Image::driver(Driver::IMAGICK));
    }

    public function testEncodersProduceBinaryOutput(): void
    {
        $image = Image::create(4, 4);

        self::assertNotEmpty($image->encode(Image::pngEncoder())->toString());
        self::assertNotEmpty($image->encode(Image::jpegEncoder())->toString());
        self::assertNotEmpty($image->encode(Image::webpEncoder())->toString());
    }

    public function testEncodersReturnExpectedTypes(): void
    {
        self::assertInstanceOf(PngEncoder::class, Image::pngEncoder());
        self::assertInstanceOf(JpegEncoder::class, Image::jpegEncoder());
        self::assertInstanceOf(WebpEncoder::class, Image::webpEncoder());
    }

    public function testBlurhashPipelineFromCreatedImage(): void
    {
        $image = Image::create(32, 16);
        $hash = Blurhash::encode($image, 16);
        $dataUri = Blurhash::decodeToDataUri($hash, 16);

        self::assertStringStartsWith('<', $hash);
        self::assertStringStartsWith('data:image/png;base64,', $dataUri);
    }

    public function testDecodeToImageFromPixelMap(): void
    {
        $map = PixelMap::solid(4, 4, [0, 255, 0]);
        $image = Blurhash::decodeToImage($map);

        self::assertSame(4, $image->width());
        self::assertSame(4, $image->height());
    }

    private static function fixturePath(
        string $filename,
    ): string {
        return \dirname(__DIR__) . '/Fixtures/' . $filename;
    }
}
