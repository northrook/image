<?php

declare(strict_types = 1);

namespace Northrook\Core;

use Intervention\Image\Drivers\{AbstractEncoder, Gd, Imagick};
use Intervention\Image\Encoders\{JpegEncoder, PngEncoder, WebpEncoder};
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\{ImageInterface};
use LogicException;
use Northrook\Core\Image\{Aspect, Driver};
use SplFileInfo;
use Stringable;

final class Image
{
    private static ImageManager $imageProcessor;

    private static Driver $imageDriver;

    protected static int $pixelMapClamp = 128;

    /**
     * @param ImageInterface|list<list<int[]>>|SplFileInfo|string|Stringable  $source
     * @param int                                                             $resolution  [64]
     *
     * @return list<list<int[]>>
     */
    public static function getPixelMap(
        array|SplFileInfo|Stringable|string|ImageInterface $source,
        int $resolution = 64,
    ): array {
        if ($resolution < 4 || $resolution > self::$pixelMapClamp) {
            $resolution = (int) num_clamp($resolution, 4, self::$pixelMapClamp);
        }

        $image = $source instanceof ImageInterface ? $source : Image::from($source);

        [$cols, $rows] = Aspect::from($image)->scaleShortest($resolution);

        $cols = min($cols, $image->width());
        $rows = min($rows, $image->height());

        $map = [];

        for ($row = 0; $row < $rows; $row++) {
            $line = [];

            for ($col = 0; $col < $cols; $col++) {
                $x = min(
                    (int) \floor(( $col + 0.5 ) * $image->width() / $cols),
                    $image->width() - 1,
                );
                $y = min(
                    (int) \floor(( $row + 0.5 ) * $image->height() / $rows),
                    $image->height() - 1,
                );

                $line[] = $image->pickColor($x, $y)->toArray();
            }

            $map[] = $line;
        }

        return $map;
    }

    public static function mimeType(
        string $filePath,
    ): string {
        return (
            \getimagesize($filePath)['mime'] ?? throw new LogicException('Unable to get image mime type from '
            . $filePath)
        );
    }

    public static function pngEncoder(
        bool $interlaced = false,
        bool $indexed = false,
    ): PngEncoder {
        return Driver::isImagick()
            ? new Imagick\Encoders\PngEncoder($interlaced, $indexed)
            : new Gd\Encoders\PngEncoder($interlaced, $indexed);
    }

    public static function jpegEncoder(
        int $quality = AbstractEncoder::DEFAULT_QUALITY,
        bool $progressive = false,
        null|bool $strip = null,
    ): JpegEncoder {
        return Driver::isImagick()
            ? new Imagick\Encoders\JpegEncoder($quality, $progressive, $strip)
            : new Gd\Encoders\JpegEncoder($quality, $progressive, $strip);
    }

    public static function webpEncoder(
        int $quality = AbstractEncoder::DEFAULT_QUALITY,
        null|bool $strip = null,
    ): WebpEncoder {
        return Driver::isImagick()
            ? new Imagick\Encoders\WebpEncoder($quality, $strip)
            : new Gd\Encoders\WebpEncoder($quality, $strip);
    }

    public static function from(
        mixed $input,
    ): ImageInterface {
        if ($input instanceof Stringable) {
            $input = $input->__toString();
        }
        return Image::getImageProcessor()->read($input);
    }

    public static function create(int $width, int $height): ImageInterface
    {
        return Image::getImageProcessor()->create($width, $height);
    }

    /**
     * @param null|Driver  $is
     *
     * @return ($is is null ? Driver : bool)
     */
    public static function driver(
        null|Driver $is = null,
    ): Driver|bool {
        Image::$imageDriver ??= Driver::detect();

        if ($is) {
            return Image::$imageDriver === $is;
        }

        return Image::$imageDriver;
    }

    public static function getImageProcessor(): ImageManager
    {
        return Image::$imageProcessor ??= new ImageManager(
            driver: Driver::isImagick() ? new Imagick\Driver() : new Gd\Driver(),
        );
    }

    public static function setPixelMapClamp(int $pixelMapClamp): void
    {
        self::$pixelMapClamp = $pixelMapClamp;
    }
}
