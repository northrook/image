<?php

declare(strict_types = 1);

namespace Northrook\Core\Tests\Unit;

use InvalidArgumentException;
use Northrook\Core\Image\Blurhash;
use Northrook\Core\Tests\Support\PixelMap;
use PHPUnit\Framework\TestCase;

final class BlurhashTest extends TestCase
{
    private const string GOLDEN_HASH = '00TI?r';

    protected function tearDown(): void
    {
        Blurhash::setMaxPixels(16_384);
    }

    public function testEncodeDecodeRoundTripPreservesDimensions(): void
    {
        $map = PixelMap::solid(4, 4, [255, 0, 0]);

        $hash = Blurhash::encode($map, 64, [1, 1], false);
        $decoded = Blurhash::decode($hash, 4, 4);

        self::assertCount(4, $decoded);
        self::assertCount(4, $decoded[0]);
    }

    public function testGoldenHashDecodesToMostlyRed(): void
    {
        $decoded = Blurhash::decode(self::GOLDEN_HASH, 8, 8);
        [$r, $g, $b] = $decoded[4][4];

        self::assertGreaterThan(200, $r);
        self::assertLessThan(32, $g);
        self::assertLessThan(32, $b);
    }

    public function testEncodeIsDeterministicForFixedInput(): void
    {
        $map = PixelMap::solid(4, 4, [255, 0, 0]);

        $first = Blurhash::encode($map, 64, [1, 1], false);
        $second = Blurhash::encode($map, 64, [1, 1], false);

        self::assertSame(self::GOLDEN_HASH, $first);
        self::assertSame($first, $second);
    }

    public function testSizePrefixIsEmbeddedAndParsed(): void
    {
        $map = PixelMap::solid(4, 4, [255, 0, 0]);
        $hash = Blurhash::encode($map, 64, [1, 1], true);

        self::assertSame('<4:4>' . self::GOLDEN_HASH, $hash);

        $decoded = Blurhash::decode($hash);

        self::assertCount(4, $decoded);
        self::assertCount(4, $decoded[0]);
    }

    public function testWidthOnlyWithSizePrefixDerivesHeight(): void
    {
        $map = PixelMap::solid(4, 8, [0, 128, 255]);
        $hash = Blurhash::encode($map, 64, [1, 2], true);

        $decoded = Blurhash::decode($hash, 16);

        self::assertCount(8, $decoded);
        self::assertCount(16, $decoded[0]);
    }

    public function testRatioFalseUsesFourByFourComponents(): void
    {
        $map = PixelMap::solid(4, 4, [255, 0, 0]);

        $inferred = Blurhash::encode($map, 64, null, false);
        $fixed = Blurhash::encode($map, 64, false, false);

        self::assertSame(36, \strlen($fixed));
        self::assertNotSame($inferred, $fixed);
    }

    public function testSourceIsLinearAcceptsPreprocessedPixelMap(): void
    {
        $map = PixelMap::solid(4, 4, [255, 0, 0]);

        $hash = Blurhash::encode($map, 64, [1, 1], false, sourceIsLinear: true);

        self::assertSame(self::GOLDEN_HASH, $hash);
    }

    public function testPunchChangesDecodedOutput(): void
    {
        $map = [];
        for ($y = 0; $y < 8; $y++) {
            $row = [];
            for ($x = 0; $x < 8; $x++) {
                $row[] = [$x * 32, $y * 32, 128];
            }
            $map[] = $row;
        }

        $hash = Blurhash::encode($map, 64, [4, 4], false);
        $normal = Blurhash::decode($hash, 16, 16, 1.0);
        $punched = Blurhash::decode($hash, 16, 16, 3.0);

        $difference = 0;
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $difference += \abs($normal[$y][$x][0] - $punched[$y][$x][0]);
            }
        }

        self::assertGreaterThan(0, $difference);
    }

    public function testEncodeRejectsMapsAbovePixelBudget(): void
    {
        $map = PixelMap::solid(129, 128, [0, 0, 0]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Decode pixel budget exceeded');

        Blurhash::encode($map, 64, [1, 1], false);
    }

    public function testDecodeRejectsOutputAbovePixelBudget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Decode pixel budget exceeded');

        Blurhash::decode(self::GOLDEN_HASH, 200, 200);
    }

    public function testSetMaxPixelsIsHonoured(): void
    {
        Blurhash::setMaxPixels(64);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Decode pixel budget exceeded');

        Blurhash::decode(self::GOLDEN_HASH, 9, 8);
    }

    public function testDecodeRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Blurhash string must be at least 6 characters');

        Blurhash::decode('');
    }

    public function testDecodeRejectsLengthMismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Blurhash length mismatch');

        Blurhash::decode('00TI?r!', 4, 4);
    }

    public function testDecodeRejectsInvalidBase83Character(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid base83 character: '!'.");

        Blurhash::decode('00!I?r', 4, 4);
    }

    public function testDecodeWithoutDimensionsOrPrefixThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Decode dimensions must be positive integers.');

        Blurhash::decode(self::GOLDEN_HASH);
    }
}
