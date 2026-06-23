<?php

declare(strict_types = 1);

namespace Northrook\Core\Tests\Unit;

use InvalidArgumentException;
use Intervention\Image\Interfaces\ImageInterface;
use Northrook\Core\Image\Aspect;
use Northrook\Core\Image\Orientation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AspectTest extends TestCase
{
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

    public function testFromUnreadablePathThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Aspect::from('/path/does/not/exist.png');
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

    private static function fixturePath(
        string $filename,
    ): string {
        return \dirname(__DIR__) . '/Fixtures/' . $filename;
    }
}
