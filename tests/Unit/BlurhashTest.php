<?php

declare(strict_types=1);

namespace Northrook\Core\Tests\Unit;

use InvalidArgumentException;
use Intervention\Image\Interfaces\{ColorInterface, ImageInterface};
use Northrook\Core\Image\Aspect;
use Northrook\Core\Image\Blurhash;
use Northrook\Core\Tests\Support\PixelMap;
use PHPUnit\Framework\TestCase;

final class BlurhashTest extends TestCase
{
    private const string GOLDEN_HASH = '00TI?r';

    /** 8×8 RGB gradient, 4×4 components — verified against kornrunner/php-blurhash. */
    private const string KORNRUNNER_GRADIENT_HASH = 'UAF={t4RWp~p_fD?a|r2e;f7fQf7~pF_a|t7';

    protected function tearDown(): void
    {
        Blurhash::setMaxPixels(16_384);
    }

    public function testEncodeDecodeRoundTripPreservesDimensions(): void
    {
        $map = PixelMap::solid(4, 4, [255, 0, 0]);

        $hash    = Blurhash::encode($map, 64, [1, 1], false);
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

        $first  = Blurhash::encode($map, 64, [1, 1], false);
        $second = Blurhash::encode($map, 64, [1, 1], false);

        self::assertSame(self::GOLDEN_HASH, $first);
        self::assertSame($first, $second);
    }

    public function testEncodeMatchesKornrunnerGradientFixture(): void
    {
        $hash = Blurhash::encode(PixelMap::gradient(8, 8), 64, [4, 4], false);

        self::assertSame(self::KORNRUNNER_GRADIENT_HASH, $hash);
    }

    public function testSizePrefixIsEmbeddedAndParsed(): void
    {
        $map  = PixelMap::solid(4, 4, [255, 0, 0]);
        $hash = Blurhash::encode($map, 64, [1, 1], true);

        self::assertSame('<4:4>' . self::GOLDEN_HASH, $hash);

        $decoded = Blurhash::decode($hash);

        self::assertCount(4, $decoded);
        self::assertCount(4, $decoded[0]);
    }

    public function testWidthOnlyWithSizePrefixDerivesHeight(): void
    {
        $map  = PixelMap::solid(4, 8, [0, 128, 255]);
        $hash = Blurhash::encode($map, 64, [1, 2], true);

        $decoded = Blurhash::decode($hash, width: 16);

        self::assertCount(32, $decoded);
        self::assertCount(16, $decoded[0]);
    }

    public function testHeightOnlyWithSizePrefixDerivesWidth(): void
    {
        $map  = PixelMap::solid(4, 8, [0, 128, 255]);
        $hash = Blurhash::encode($map, 64, [1, 2], true);

        $decoded = Blurhash::decode($hash, height: 32);

        self::assertCount(32, $decoded);
        self::assertCount(16, $decoded[0]);
    }

    public function testDecodeWithSizePrefixUsesExplicitDimensions(): void
    {
        $map  = PixelMap::solid(4, 8, [0, 128, 255]);
        $hash = Blurhash::encode($map, 64, [1, 2], true);

        $decoded = Blurhash::decode($hash, 16, 32);

        self::assertCount(32, $decoded);
        self::assertCount(16, $decoded[0]);
    }

    public function testDecodeWithSizePrefixAcceptsSpreadScaleShortestDimensions(): void
    {
        $aspect = Aspect::from($this->createConfiguredMock(ImageInterface::class, [
            'width' => 85,
            'height' => 64,
        ]));

        $map  = PixelMap::solid(85, 64, [128, 64, 32]);
        $hash = Blurhash::encode($map, 64, [1, 1], true);

        $decoded = Blurhash::decode($hash, ...$aspect->scaleShortest(8));

        self::assertSame($aspect->scaleShortest(8), [\count($decoded[0]), \count($decoded)]);
    }

    public function testFourByFourComponentsUsesFixedGrid(): void
    {
        $map = PixelMap::solid(4, 4, [255, 0, 0]);

        $inferred = Blurhash::encode($map, 64, null, false);
        $fixed    = Blurhash::encode($map, 64, [4, 4], false);

        self::assertSame(36, \strlen($fixed));
        self::assertNotSame($inferred, $fixed);
    }

    public function testSourceIsLinearAcceptsPreprocessedPixelMap(): void
    {
        $map = PixelMap::solid(4, 4, [255, 0, 0]);

        $hash = Blurhash::encode($map, 64, [1, 1], false, sourceIsLinear: true);

        self::assertSame(self::GOLDEN_HASH, $hash);
    }

    public function testEncodeCompositesFullyTransparentPixelsOntoWhiteMatte(): void
    {
        $transparent = [[[255, 0, 0, 0]]];
        $white       = [[[255, 255, 255]]];

        self::assertSame(
            Blurhash::encode($white, 64, [1, 1], false),
            Blurhash::encode($transparent, 64, [1, 1], false),
        );
    }

    public function testEncodeCompositesOntoCustomMatte(): void
    {
        $transparent = [[[255, 0, 0, 0]]];
        $matteBlue   = [[[0, 0, 255]]];

        self::assertSame(
            Blurhash::encode($matteBlue, 64, [1, 1], false),
            Blurhash::encode($transparent, 64, [1, 1], false, matte: [0, 0, 255]),
        );
    }

    public function testEncodeWithMatteNullIgnoresAlpha(): void
    {
        $transparent = [[[255, 0, 0, 0]]];
        $red         = [[[255, 0, 0]]];

        self::assertSame(
            Blurhash::encode($red, 64, [1, 1], false),
            Blurhash::encode($transparent, 64, [1, 1], false, matte: null),
        );
    }

    public function testEncodeLeavesOpaqueRgbaUnchanged(): void
    {
        $rgb  = PixelMap::solid(4, 4, [255, 0, 0]);
        $rgba = PixelMap::solid(4, 4, [255, 0, 0, 255]);

        self::assertSame(
            Blurhash::encode($rgb, 64, [1, 1], false),
            Blurhash::encode($rgba, 64, [1, 1], false),
        );
    }

    public function testEncodeRejectsInvalidAlpha(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('alpha must be between 0 and 255');

        Blurhash::encode([[[255, 0, 0, 300]]], 64, [1, 1], false);
    }

    public function testEncodeRejectsInvalidMatte(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('matte channels must be between 0 and 255');

        Blurhash::encode([[[255, 0, 0, 0]]], 64, [1, 1], false, matte: [0, 0, 500]);
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

        $hash    = Blurhash::encode($map, 64, [4, 4], false);
        $normal  = Blurhash::decode($hash, 16, 16, 1.0);
        $punched = Blurhash::decode($hash, 16, 16, 3.0);

        $difference = 0;
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $difference += \abs($normal[$y][$x][0] - $punched[$y][$x][0]);
            }
        }

        self::assertGreaterThan(0, $difference);
    }

    public function testEncodeFromExtremeAspectImageFitsPixelBudget(): void
    {
        $image = $this->createConfiguredMock(ImageInterface::class, [
            'width' => 10_000,
            'height' => 10,
        ]);
        $image->method('pickColor')->willReturnCallback(
            fn (): ColorInterface => $this->createConfiguredMock(ColorInterface::class, [
                'toArray' => [255, 0, 0, 255],
            ]),
        );

        $hash = Blurhash::encode($image, 128, [1, 1], false);

        self::assertGreaterThanOrEqual(6, \strlen($hash));
        self::assertNotEmpty(Blurhash::decode($hash, 32, 16));
    }

    public function testEncodeRejectsMapsAbovePixelBudget(): void
    {
        $map = PixelMap::solid(129, 128, [0, 0, 0]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pixel budget');

        Blurhash::encode($map, 64, [1, 1], false);
    }

    public function testDecodeRejectsOutputAbovePixelBudget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pixel budget');

        Blurhash::decode(self::GOLDEN_HASH, 200, 200);
    }

    public function testSetMaxPixelsIsHonoured(): void
    {
        Blurhash::setMaxPixels(64);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pixel budget');

        Blurhash::decode(self::GOLDEN_HASH, 9, 8);
    }

    public function testSetMaxPixelsRejectsOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pixel map budget must be between 32 and 16777215');

        self::invokeSetMaxPixels(16);
    }

    public function testDecodeRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 6 characters');

        Blurhash::decode('');
    }

    public function testDecodeRejectsLengthMismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match component grid');

        Blurhash::decode('00TI?r!', 4, 4);
    }

    public function testDecodeRejectsInvalidBase83Character(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("invalid character '!'");

        Blurhash::decode('00!I?r', 4, 4);
    }

    public function testDecodeWithoutDimensionsOrPrefixThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires both width and height');

        Blurhash::decode(self::GOLDEN_HASH);
    }

    public function testEncodeRejectsEmptyMap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty list of rows');

        Blurhash::encode([], 64, [1, 1], false);
    }

    public function testEncodeRejectsEmptyRows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rows must not be empty');

        Blurhash::encode([[]], 64, [1, 1], false);
    }

    public function testEncodeRejectsJaggedMap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be rectangular');

        Blurhash::encode([
            [[255, 0, 0], [0, 255, 0]],
            [[0, 0, 255]],
        ], 64, [1, 1], false);
    }

    public function testEncodeRejectsInvalidRowType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('row 1 must be a list');

        self::invokeEncode([
            [[255, 0, 0]],
            'not-a-row',
        ], 64, [1, 1], false);
    }

    public function testEncodeRejectsInvalidPixelShape(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pixel at [0][1] must be an RGB list');

        Blurhash::encode([
            [[255, 0, 0], [0, 255]],
        ], 64, [1, 1], false);
    }

    public function testEncodeRejectsNonNumericChannel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('channel 1 must be numeric');

        self::invokeEncode([
            [[255, 'x', 0]],
        ], 64, [1, 1], false);
    }

    public function testDecodeToImageRejectsOversizedMap(): void
    {
        $map = PixelMap::solid(129, 128, [0, 0, 0]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pixel budget');

        Blurhash::decodeToImage($map);
    }

    public function testDecodeToImageRejectsMalformedMap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be rectangular');

        Blurhash::decodeToImage([
            [[255, 0, 0], [0, 255, 0]],
            [[0, 0, 255]],
        ]);
    }

    public function testDecodeToImageRejectsDimensionsWithPixelMap(): void
    {
        $map = PixelMap::solid(4, 4, [255, 0, 0]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('`$width` and `$height` only apply when decoding a blurhash string');

        Blurhash::decodeToImage($map, width: 4);
    }

    /**
     * @param mixed  ...$args
     */
    private static function invokeEncode(mixed ...$args): mixed
    {
        return ( new \ReflectionMethod(Blurhash::class, 'encode') )->invokeArgs(null, $args);
    }

    /**
     * @param mixed  $maxPixels
     */
    private static function invokeSetMaxPixels(mixed $maxPixels): mixed
    {
        return ( new \ReflectionMethod(Blurhash::class, 'setMaxPixels') )->invoke(null, $maxPixels);
    }
}
