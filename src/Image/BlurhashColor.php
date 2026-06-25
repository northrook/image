<?php

declare(strict_types=1);

namespace Northrook\Core\Image;

use InvalidArgumentException;

/**
 * @internal
 */
final class BlurhashColor
{
    private function __construct() {}

    /**
     * @param float|int  $r
     * @param float|int  $g
     * @param float|int  $b
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function rgb(
        float|int $r,
        float|int $g,
        float|int $b,
    ): array {
        return [
            self::colorRgb($r),
            self::colorRgb($g),
            self::colorRgb($b),
        ];
    }

    public static function colorLinear(
        float|int $value,
    ): float {
        $value = (float) $value / 255;

        return $value <= 0.040_45 ? $value / 12.92 : \pow(( $value + 0.055 ) / 1.055, 2.4);
    }

    public static function colorRgb(
        float $value,
    ): int {
        $normalized = Internal::clamp($value, 0, 1);
        $result     = $normalized <= 0.003_130_8
            ? (int) \round(( $normalized * 12.92 * 255 ) + 0.5)
            : (int) \round(( ( ( 1.055 * \pow($normalized, 1 / 2.4) ) - 0.055 ) * 255 ) + 0.5);

        return (int) Internal::clamp($result, 0, 255);
    }

    /**
     * @param array<int, array<int, array<int|float>>>  $map
     *
     * @return array<int, array<int, float[]>>
     */
    public static function linearMap(
        array $map,
        int $width,
        int $height,
    ): array {
        $linearMap = [];

        for ($y = 0; $y < $height; $y++) {
            $line = [];

            for ($x = 0; $x < $width; $x++) {
                $pixel  = $map[$y][$x];
                $line[] = [
                    self::colorLinear($pixel[0]),
                    self::colorLinear($pixel[1]),
                    self::colorLinear($pixel[2]),
                ];
            }

            $linearMap[] = $line;
        }

        return $linearMap;
    }

    /**
     * @param array{0: int|float, 1: int|float, 2: int|float}  $matte
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function guardMatte(
        array $matte,
    ): array {
        if (\count($matte) < 3) {
            throw new InvalidArgumentException(
                'Blurhash matte must be an sRGB triplet [r, g, b].',
            );
        }

        $channels = [];

        foreach ($matte as $channel) {
            if (! \is_int($channel) && ! \is_float($channel)) {
                throw new InvalidArgumentException(
                    'Blurhash matte channels must be numeric.',
                );
            }

            $value = (int) $channel;

            if ($value < 0 || $value > 255) {
                throw new InvalidArgumentException(\sprintf(
                    'Blurhash matte channels must be between 0 and 255; got %d.',
                    $value,
                ));
            }

            $channels[] = $value;
        }

        return [$channels[0], $channels[1], $channels[2]];
    }

    /**
     * Composite RGBA samples onto an sRGB matte in linear space.
     *
     * @param array<int, array<int, int[]|float[]>>  $map
     * @param array{0: int, 1: int, 2: int}          $matte
     *
     * @return array<int, array<int, list<int>|list<float>>>
     */
    public static function flattenAlpha(
        array $map,
        array $matte,
        bool $pixelsAreLinear,
    ): array {
        $matteLinear = [
            self::colorLinear($matte[0]),
            self::colorLinear($matte[1]),
            self::colorLinear($matte[2]),
        ];

        $flattened = [];

        foreach ($map as $row) {
            $line = [];

            foreach ($row as $pixel) {
                if (\count($pixel) < 4) {
                    $line[] = [$pixel[0], $pixel[1], $pixel[2]];
                    continue;
                }

                if ($pixel[3] === 0) {
                    $line[] = [$matte[0], $matte[1], $matte[2]];
                    continue;
                }

                if ($pixel[3] === 255 && ! $pixelsAreLinear) {
                    $line[] = [(int) $pixel[0], (int) $pixel[1], (int) $pixel[2]];
                    continue;
                }

                $alpha    = $pixel[3] / 255;
                $channels = $pixelsAreLinear
                    ? [(float) $pixel[0], (float) $pixel[1], (float) $pixel[2]]
                    : [
                        self::colorLinear($pixel[0]),
                        self::colorLinear($pixel[1]),
                        self::colorLinear($pixel[2]),
                    ];

                $composited = [];

                for ($channel = 0; $channel < 3; $channel++) {
                    $linear       = ( $channels[$channel] * $alpha ) + ( $matteLinear[$channel] * ( 1 - $alpha ) );
                    $composited[] = $pixelsAreLinear ? $linear : self::colorRgb($linear);
                }

                $line[] = $composited;
            }

            $flattened[] = $line;
        }

        return $flattened;
    }

    /**
     * @param array<int, array<int, int[]|float[]>>  $map
     */
    public static function mapHasAlpha(
        array $map,
    ): bool {
        return array_any(
            $map,
            static fn($row) => array_any(
                $row,
                static fn($pixel) => \count($pixel) >= 4,
            ),
        );
    }

    public static function guardRgbPixel(
        mixed $pixel,
        int $row,
        int $col,
        bool $requireSrgbRange = false,
    ): void {
        if (! \is_array($pixel) || ! \array_is_list($pixel) || \count($pixel) < 3) {
            throw new InvalidArgumentException(\sprintf(
                'Blurhash map pixel at [%d][%d] must be an RGB list [r, g, b] or RGBA [r, g, b, a].',
                $row,
                $col,
            ));
        }

        for ($channel = 0; $channel < 3; $channel++) {
            if (! \is_int($pixel[$channel]) && ! \is_float($pixel[$channel])) {
                throw new InvalidArgumentException(\sprintf(
                    'Blurhash map pixel at [%d][%d] channel %d must be numeric.',
                    $row,
                    $col,
                    $channel,
                ));
            }

            if ($requireSrgbRange) {
                $value = (int) $pixel[$channel];

                if ($value < 0 || $value > 255) {
                    throw new InvalidArgumentException(\sprintf(
                        'Blurhash map pixel at [%d][%d] channel %d must be between 0 and 255; got %s.',
                        $row,
                        $col,
                        $channel,
                        $pixel[$channel],
                    ));
                }
            }
        }

        if (\count($pixel) >= 4 && ! \is_int($pixel[3]) && ! \is_float($pixel[3])) {
            throw new InvalidArgumentException(\sprintf(
                'Blurhash map pixel at [%d][%d] alpha must be numeric.',
                $row,
                $col,
            ));
        }

        if (\count($pixel) >= 4 && ( $pixel[3] < 0 || $pixel[3] > 255 )) {
            throw new InvalidArgumentException(\sprintf(
                'Blurhash map pixel at [%d][%d] alpha must be between 0 and 255; got %s.',
                $row,
                $col,
                $pixel[3],
            ));
        }
    }
}
