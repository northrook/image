<?php

declare(strict_types = 1);

namespace Northrook\Core\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use Intervention\Image\Interfaces\ImageInterface;
use Northrook\Core\Image;
use Northrook\Core\Image\Aspect;
use Northrook\Core\Image\Orientation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AspectTest extends TestCase
{
    protected function tearDown(): void
    {
        Image::setPixelMapClamp(128);
    }

    public function testFromImageInterface(): void
    {
        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => 1920,
            'height' => 1080,
        ]);

        $aspect = Aspect::from($image);

        self::assertSame(16, $aspect->width);
        self::assertSame(9, $aspect->height);
        self::assertSame(120, $aspect->divisor);
        self::assertSame(Orientation::LANDSCAPE, $aspect->orientation);
        self::assertSame('16/9', $aspect->getRatio());
        self::assertSame(1.7778, $aspect->getFloat());
    }

    public function testFromReadableFile(): void
    {
        $aspect = Aspect::from(self::fixturePath('square.png'));

        self::assertSame(1, $aspect->width);
        self::assertSame(1, $aspect->height);
        self::assertSame(Orientation::SQUARE, $aspect->orientation);
    }

    public function testFromMissingPathThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("File '/path/does/not/exist.png' does not exist.");

        Aspect::from('/path/does/not/exist.png');
    }

    public function testFromZeroDimensionsThrows(): void
    {
        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => 0,
            'height' => 0,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image dimensions must be positive integers');

        Aspect::from($image);
    }

    /**
     * @param array{0: int, 1: int}  $expected
     */
    #[DataProvider('scaleShortestProvider')]
    public function testScaleShortest(
        int $width,
        int $height,
        int $edge,
        array $expected,
    ): void {
        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => $width,
            'height' => $height,
        ]);

        self::assertSame($expected, Aspect::from($image)->scaleShortest($edge));
    }

    /**
     * @return iterable<string, array{int, int, int, array{0: int, 1: int}}>
     */
    public static function scaleShortestProvider(): iterable
    {
        yield 'landscape' => [1920, 1080, 64, [114, 64]];
        yield 'portrait' => [1080, 1920, 64, [64, 114]];
        yield 'square' => [512, 512, 32, [32, 32]];
    }

    public function testScaleLongest(): void
    {
        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => 1920,
            'height' => 1080,
        ]);

        self::assertSame([128, 72], Aspect::from($image)->scaleLongest(128));
    }

    public function testScaleWidthAndHeight(): void
    {
        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => 1920,
            'height' => 1080,
        ]);

        $aspect = Aspect::from($image);

        self::assertSame([320, 180], $aspect->scaleWidth(320));
        self::assertSame([320, 180], $aspect->scaleHeight(180));
    }

    public function testScaleLongestRejectsZeroShortEdge(): void
    {
        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => 1000,
            'height' => 1,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Scaled dimensions must be positive');

        Aspect::from($image)->scaleLongest(4);
    }

    public function testScaleWidthRejectsZeroHeight(): void
    {
        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => 1000,
            'height' => 1,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Scaled dimensions must be positive');

        Aspect::from($image)->scaleWidth(4);
    }

    public function testScaleHeightRejectsZeroWidth(): void
    {
        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => 1,
            'height' => 1000,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Scaled dimensions must be positive');

        Aspect::from($image)->scaleHeight(4);
    }

    public function testScaleLongestRejectsEdgeBelowMinimum(): void
    {
        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => 1920,
            'height' => 1080,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Longest edge must be at least 4');

        Aspect::from($image)->scaleLongest(3);
    }

    public function testScaleShortestRejectsEdgeAboveClamp(): void
    {
        Image::setPixelMapClamp(64);

        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => 1920,
            'height' => 1080,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Shortest edge must be at most 64');

        Aspect::from($image)->scaleShortest(128);
    }

    public function testScaleLongestRejectsEdgeAboveClamp(): void
    {
        Image::setPixelMapClamp(64);

        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => 1920,
            'height' => 1080,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Longest edge must be at most 64');

        Aspect::from($image)->scaleLongest(128);
    }

    private static function fixturePath(
        string $filename,
    ): string {
        return \dirname(__DIR__) . '/Fixtures/' . $filename;
    }
}
