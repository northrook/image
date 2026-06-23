<?php

declare(strict_types = 1);

namespace Northrook\Core\Tests\Unit;

use Intervention\Image\Interfaces\{ColorInterface, ImageInterface};
use Northrook\Core\Image;
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

        $low = Image::getPixelMap($image, 1);
        $high = Image::getPixelMap($image, 999);

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

        $map = Image::getPixelMap($image, 64);

        self::assertLessThanOrEqual(8, \count($map));
        self::assertLessThanOrEqual(8, \count($map[0]));
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
