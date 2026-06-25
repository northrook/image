<?php

declare(strict_types = 1);

namespace Northrook\Core\Tests\Unit;

use InvalidArgumentException;
use Northrook\Core\Image\Orientation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrientationTest extends TestCase
{
    #[DataProvider('orientationProvider')]
    public function testFrom(
        int $width,
        int $height,
        Orientation $expected,
    ): void {
        self::assertSame($expected, Orientation::from($width, $height));
    }

    /**
     * @return iterable<string, array{int, int, Orientation}>
     */
    public static function orientationProvider(): iterable
    {
        yield 'landscape' => [16, 9, Orientation::LANDSCAPE];
        yield 'portrait' => [9, 16, Orientation::PORTRAIT];
        yield 'square' => [8, 8, Orientation::SQUARE];
    }

    #[DataProvider('invalidDimensionsProvider')]
    public function testFromRejectsInvalidDimensions(
        int $width,
        int $height,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image dimensions must be positive integers');

        Orientation::from($width, $height);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidDimensionsProvider(): iterable
    {
        yield 'zero dimensions' => [0, 0];
        yield 'zero width' => [0, 8];
        yield 'zero height' => [8, 0];
    }

    public function testFromRejectsOversizedDimensions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image dimensions are too large');

        Orientation::from(2_000_000_000, 2_500_000_000);
    }
}
