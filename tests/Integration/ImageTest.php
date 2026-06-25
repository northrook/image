<?php

declare(strict_types = 1);

namespace Northrook\Core\Tests\Integration;

use Intervention\Image\Encoders\{JpegEncoder, PngEncoder, WebpEncoder};
use InvalidArgumentException;
use Northrook\Core\Image;
use Northrook\Core\Image\Blurhash;
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

    public function testGetPixelMapRejectsInvalidResolution(): void
    {
        $image = Image::create(4, 4);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pixel map resolution must be at least 4');

        Image::getPixelMap($image, 1);
    }

    public function testGetPixelMapRejectsResolutionAboveClamp(): void
    {
        $image = Image::create(4, 4);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pixel map resolution must be at most 128');

        Image::getPixelMap($image, 999);
    }

    public function testSetPixelMapClamp(): void
    {
        Image::setPixelMapClamp(8);
        $image = Image::create(16, 16);
        $map = Image::getPixelMap($image, 8);

        self::assertLessThanOrEqual(8, \count($map));
        self::assertLessThanOrEqual(8, \count($map[0]));
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
        $dataUri = Blurhash::decodeToDataUri($hash, width: 16);

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

    public function testDecodeToImageFromHashString(): void
    {
        $map   = PixelMap::solid(4, 4, [255, 0, 0]);
        $hash  = Blurhash::encode($map, 64, [1, 1], false);
        $image = Blurhash::decodeToImage($hash, 4, 4);

        self::assertSame(4, $image->width());
        self::assertSame(4, $image->height());
    }

    public function testDecodeToDataUriFromHashString(): void
    {
        $map     = PixelMap::solid(4, 4, [255, 0, 0]);
        $hash    = Blurhash::encode($map, 64, [1, 1], false);
        $dataUri = Blurhash::decodeToDataUri($hash, 4, 4);

        self::assertStringStartsWith('data:image/png;base64,', $dataUri);
    }
}
