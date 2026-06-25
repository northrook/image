<?php

declare(strict_types=1);

namespace Northrook\Core\Image;

use InvalidArgumentException;

final class PixelMapLimits
{
    public const int MIN = 4;

    public const int MAX = 512;

    public const int EXTREME_ASPECT_RATIO = 10;

    /** Default upper bound on width × height before {@see Image::from()} decodes a file. */
    public const int DEFAULT_MAX_LOAD_PIXELS = 16_777_215;

    private static int $clamp = 128;

    /** @var int<32, 16777215> */
    private static int $maxPixels = 16_384;

    private static int $maxLoadPixels = self::DEFAULT_MAX_LOAD_PIXELS;

    private function __construct() {}

    public static function clamp(): int
    {
        return self::$clamp;
    }

    public static function setClamp(int $clamp): void
    {
        self::$clamp = self::guardClamp($clamp);
    }

    /**
     * @return int<32, 16777215>
     */
    public static function maxPixels(): int
    {
        return self::$maxPixels;
    }

    /**
     * @param int<32, 16777215> $maxPixels
     */
    public static function setMaxPixels(int $maxPixels): void
    {
        if ($maxPixels < 32 || $maxPixels > 16_777_215) {
            throw new InvalidArgumentException(
                \sprintf(
                    'Pixel map budget must be between 32 and 16777215; got %d.',
                    $maxPixels,
                ),
            );
        }

        self::$maxPixels = $maxPixels;
    }

    public static function maxLoadPixels(): int
    {
        return self::$maxLoadPixels;
    }

    public static function setMaxLoadPixels(int $maxLoadPixels): void
    {
        if ($maxLoadPixels < 1 || $maxLoadPixels > 16_777_215) {
            throw new InvalidArgumentException(\sprintf(
                'Image load pixel budget must be between 1 and 16777215; got %d.',
                $maxLoadPixels,
            ));
        }

        self::$maxLoadPixels = $maxLoadPixels;
    }

    /**
     * Reject image dimensions before full decoding when width × height exceeds the load budget.
     */
    public static function guardLoadDimensions(int $width, int $height): void
    {
        Orientation::from($width, $height);

        $pixels = $width * $height;

        if ($pixels > self::$maxLoadPixels) {
            throw new InvalidArgumentException(\sprintf(
                'Image dimensions exceed load budget: %d×%d (%d pixels) exceeds limit of %d.',
                $width,
                $height,
                $pixels,
                self::$maxLoadPixels,
            ));
        }
    }

    public static function isExtremeAspect(
        int $width,
        int $height,
    ): bool {
        if ($width < 1 || $height < 1) {
            return false;
        }

        $long  = \max($width, $height);
        $short = \min($width, $height);

        return ( $long / $short ) > self::EXTREME_ASPECT_RATIO;
    }

    /**
     * @return positive-int
     */
    public static function guardResolution(int $resolution): int
    {
        if ($resolution < self::MIN) {
            throw new InvalidArgumentException(\sprintf(
                'Pixel map resolution must be at least %d; %d given.',
                self::MIN,
                $resolution,
            ));
        }

        if ($resolution > self::$clamp) {
            throw new InvalidArgumentException(\sprintf(
                'Pixel map resolution must be at most %d (current clamp); %d given.',
                self::$clamp,
                $resolution,
            ));
        }

        return $resolution;
    }

    /**
     * Lower resolution until the scaled grid fits within the pixel budget.
     *
     * @return positive-int
     */
    public static function fitResolution(
        Aspect $aspect,
        int $resolution,
        int $imageWidth,
        int $imageHeight,
        int $maxPixels,
    ): int {
        $resolution = self::guardResolution($resolution);

        while ($resolution >= self::MIN) {
            if (self::gridPixelCount($aspect, $resolution, $imageWidth, $imageHeight) <= $maxPixels) {
                return $resolution;
            }

            $resolution--;
        }

        throw new InvalidArgumentException(\sprintf(
            'Pixel map resolution cannot fit within pixel budget of %d even at minimum resolution %d.',
            $maxPixels,
            self::MIN,
        ));
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function capGridToPixelBudget(
        int $cols,
        int $rows,
        int $maxPixels,
    ): array {
        if ($cols < 1 || $rows < 1) {
            throw new InvalidArgumentException(\sprintf(
                'Pixel map grid must be positive; got %d×%d.',
                $cols,
                $rows,
            ));
        }

        if (( $cols * $rows ) <= $maxPixels) {
            return [$cols, $rows];
        }

        $scale = \sqrt($maxPixels / ( $cols * $rows ));
        $cols  = \max(1, (int) \floor($cols * $scale));
        $rows  = \max(1, (int) \floor($rows * $scale));

        while (( $cols * $rows ) > $maxPixels) {
            if ($cols <= 1 && $rows <= 1) {
                break;
            }

            if ($cols >= $rows) {
                $cols--;
            } else {
                $rows--;
            }
        }

        if (( $cols * $rows ) > $maxPixels) {
            throw new InvalidArgumentException(\sprintf(
                'Pixel map grid exceeds pixel budget of %d; minimum grid is %d×%d (%d pixels).',
                $maxPixels,
                $cols,
                $rows,
                $cols * $rows,
            ));
        }

        return [$cols, $rows];
    }

    private static function gridPixelCount(
        Aspect $aspect,
        int $resolution,
        int $imageWidth,
        int $imageHeight,
    ): int {
        [$cols, $rows] = $aspect->scaleShortest($resolution);
        $cols = \min($cols, $imageWidth);
        $rows = \min($rows, $imageHeight);

        return $cols * $rows;
    }

    /**
     * @return positive-int
     */
    private static function guardClamp(int $clamp): int
    {
        if ($clamp < self::MIN || $clamp > self::MAX) {
            throw new InvalidArgumentException(\sprintf(
                'Pixel map clamp must be between %d and %d; %d given.',
                self::MIN,
                self::MAX,
                $clamp,
            ));
        }

        return $clamp;
    }
}
