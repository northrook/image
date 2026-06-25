<?php

declare(strict_types = 1);

namespace Northrook\Core\Tests\Unit;

use InvalidArgumentException;
use Intervention\Image\Interfaces\{ColorInterface, ImageInterface};
use Northrook\Core\Image;
use Northrook\Core\Image\PixelMapLimits;
use PHPUnit\Framework\TestCase;

final class ImagePixelMapTest extends TestCase
{
    protected function tearDown(): void
    {
        Image::setPixelMapClamp(128);
    }

    public function testGetPixelMapPreservesAspectRatio(): void
    {
        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => 16,
            'height' => 9,
        ]);
        $image->method('pickColor')->willReturnCallback(
            fn (int $x, int $y): ColorInterface => $this->color([$x, $y, 0, 255]),
        );

        $map = Image::getPixelMap($image, 8);

        self::assertCount(8, $map);
        self::assertCount(14, $map[0]);
    }

    public function testGetPixelMapDoesNotExceedSourceDimensions(): void
    {
        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => 4,
            'height' => 4,
        ]);
        $image->method('pickColor')->willReturnCallback(
            fn (): ColorInterface => $this->color([0, 0, 0, 255]),
        );

        $low = Image::getPixelMap($image, 4);
        $high = Image::getPixelMap($image, 128);

        self::assertCount(4, $low);
        self::assertCount(4, $low[0]);
        self::assertCount(4, $high);
        self::assertCount(4, $high[0]);
    }

    public function testSetPixelMapClampLimitsOutputGrid(): void
    {
        Image::setPixelMapClamp(8);

        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => 16,
            'height' => 16,
        ]);
        $image->method('pickColor')->willReturnCallback(
            fn (): ColorInterface => $this->color([0, 0, 0, 255]),
        );

        $map = Image::getPixelMap($image, 8);

        self::assertLessThanOrEqual(8, \count($map));
        self::assertLessThanOrEqual(8, \count($map[0]));
    }

    public function testGetPixelMapRejectsResolutionBelowMinimum(): void
    {
        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => 16,
            'height' => 16,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pixel map resolution must be at least 4');

        Image::getPixelMap($image, 3);
    }

    public function testGetPixelMapRejectsResolutionAboveClamp(): void
    {
        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => 16,
            'height' => 16,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pixel map resolution must be at most 128');

        Image::getPixelMap($image, 256);
    }

    public function testGetPixelMapCapsExtremeAspectToPixelBudget(): void
    {
        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => 10_000,
            'height' => 10,
        ]);
        $image->method('pickColor')->willReturnCallback(
            fn (): ColorInterface => $this->color([0, 0, 0, 255]),
        );

        $map = Image::getPixelMap($image, 128);

        self::assertLessThanOrEqual(PixelMapLimits::maxPixels(), \count($map) * \count($map[0]));
    }

    public function testGetPixelMapWithExplicitBudgetCapsGrid(): void
    {
        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => 16,
            'height' => 16,
        ]);
        $image->method('pickColor')->willReturnCallback(
            fn (): ColorInterface => $this->color([0, 0, 0, 255]),
        );

        $map = Image::getPixelMap($image, 16, maxPixels: 64);

        self::assertLessThanOrEqual(64, \count($map) * \count($map[0]));
    }

    public function testSetPixelMapClampRejectsOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pixel map clamp must be between 4 and 512');

        Image::setPixelMapClamp(3);
    }

    /**
     * @param int[] $channels
     */
    private function color(array $channels): ColorInterface
    {
        return $this->createConfiguredMock(ColorInterface::class, [
            'toArray' => $channels,
        ]);
    }
}
