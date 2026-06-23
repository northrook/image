<?php

declare(strict_types = 1);

namespace Northrook\Core\Tests\Unit;

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
        yield 'zero dimensions' => [0, 0, Orientation::SQUARE];
    }
}
