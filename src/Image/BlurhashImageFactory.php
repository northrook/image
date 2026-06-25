<?php

declare(strict_types=1);

namespace Northrook\Core\Image;

use Intervention\Image\Interfaces\ImageInterface;
use LogicException;
use Northrook\Core\Image;

/**
 * @internal
 */
final class BlurhashImageFactory
{
    private function __construct() {}

    /**
     * @param array<int, array<int, int[]>>  $map
     */
    public static function decodeToImage(
        array $map,
    ): ImageInterface {
        return match (Image::driver()) {
            Driver::IMAGICK => self::decodeToImageImagick($map),
            Driver::GD      => self::decodeToImageGd($map),
        };
    }

    /**
     * @param array<int, array<int, int[]>>  $map
     */
    private static function decodeToImageGd(
        array $map,
    ): ImageInterface {
        if (! \extension_loaded('gd')) {
            throw new LogicException('GD extension is not loaded.');
        }

        $gd = \imagecreatefromstring(self::pixelMapToPpm($map));

        if ($gd === false) {
            throw new LogicException('Failed to create GD image from pixel map buffer.');
        }

        return Image::from($gd);
    }

    /**
     * @param array<int, array<int, int[]>>  $map
     */
    private static function decodeToImageImagick(
        array $map,
    ): ImageInterface {
        if (! \extension_loaded('imagick')) {
            throw new LogicException('Imagick extension is not loaded.');
        }

        $imagick = new \Imagick();

        try {
            $imagick->readImageBlob(self::pixelMapToPpm($map));
        } catch (\Throwable $exception) {
            throw new LogicException(
                'Failed to create Imagick image from pixel map buffer.',
                previous: $exception,
            );
        }

        return Image::from($imagick);
    }

    /**
     * @param array<int, array<int, int[]>>  $map
     */
    public static function pixelMapToPpm(
        array $map,
    ): string {
        $width  = \count($map[0]);
        $height = \count($map);

        return \sprintf("P6\n%d %d\n255\n", $width, $height) . self::pixelMapToRgbBuffer($map);
    }

    /**
     * @param array<int, array<int, int[]>>  $map
     */
    public static function pixelMapToRgbBuffer(
        array $map,
    ): string {
        $chunks = [];

        foreach ($map as $row) {
            foreach ($row as $pixel) {
                $chunks[] = \pack('C3', $pixel[0], $pixel[1], $pixel[2]);
            }
        }

        return \implode('', $chunks);
    }
}
