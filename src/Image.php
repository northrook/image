<?php /** @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

namespace Northrook\Core;

use Intervention\Image\Drivers\{AbstractEncoder, Gd, Imagick};
use Intervention\Image\Encoders\{JpegEncoder, PngEncoder, WebpEncoder};
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\{ImageInterface};
use Northrook\Core\Image\{Aspect, Driver, ImageFile, PixelMapLimits};
use SplFileInfo;
use Stringable;

final class Image
{
    private static ImageManager $imageProcessor;

    public const int MIN_PIXEL_MAP_CLAMP = PixelMapLimits::MIN;

    public const int MAX_PIXEL_MAP_CLAMP = PixelMapLimits::MAX;

    private function __construct() {}

    /**
     * @param ImageInterface|SplFileInfo|string|Stringable  $source
     * @param positive-int                                  $resolution  [64]
     *
     * @return list<list<int[]>>
     */
    public static function getPixelMap(
        SplFileInfo|Stringable|string|ImageInterface $source,
        int $resolution = 64,
        null|int $maxPixels = null,
    ): array {
        $resolution = PixelMapLimits::guardResolution($resolution);

        $image = $source instanceof ImageInterface ? $source : Image::from($source);

        $aspect       = Aspect::from($image);
        $imageWidth   = $image->width();
        $imageHeight  = $image->height();
        $applyBudget  = $maxPixels !== null || PixelMapLimits::isExtremeAspect($imageWidth, $imageHeight);
        $pixelBudget  = $maxPixels ?? PixelMapLimits::maxPixels();

        if ($applyBudget) {
            $resolution = PixelMapLimits::fitResolution(
                $aspect,
                $resolution,
                $imageWidth,
                $imageHeight,
                $pixelBudget,
            );
        }

        [$cols, $rows] = $aspect->scaleShortest($resolution);

        $cols = \min($cols, $imageWidth);
        $rows = \min($rows, $imageHeight);

        if ($applyBudget) {
            [$cols, $rows] = PixelMapLimits::capGridToPixelBudget($cols, $rows, $pixelBudget);
        }

        $map = [];

        for ($row = 0; $row < $rows; $row++) {
            $line = [];

            for ($col = 0; $col < $cols; $col++) {
                $x = min((int) \floor(( ( $col + 0.5 ) * $image->width() ) / $cols), $image->width() - 1);
                $y = min((int) \floor(( ( $row + 0.5 ) * $image->height() ) / $rows), $image->height() - 1);

                $line[] = $image->pickColor($x, $y)->toArray();
            }

            $map[] = $line;
        }

        return $map;
    }

    /**
     * @param string  $filePath
     *
     * @return string
     */
    public static function mimeType(
        string $filePath,
    ): string {
        return ImageFile::open($filePath)->mimeType();
    }

    public static function pngEncoder(
        bool $interlaced = false,
        bool $indexed = false,
    ): PngEncoder {
        return Driver::detect() === Driver::IMAGICK
            ? new Imagick\Encoders\PngEncoder($interlaced, $indexed)
            : new Gd\Encoders\PngEncoder($interlaced, $indexed);
    }

    public static function jpegEncoder(
        int $quality = AbstractEncoder::DEFAULT_QUALITY,
        bool $progressive = false,
        null|bool $strip = null,
    ): JpegEncoder {
        return Driver::detect() === Driver::IMAGICK
            ? new Imagick\Encoders\JpegEncoder($quality, $progressive, $strip)
            : new Gd\Encoders\JpegEncoder($quality, $progressive, $strip);
    }

    public static function webpEncoder(
        int $quality = AbstractEncoder::DEFAULT_QUALITY,
        null|bool $strip = null,
    ): WebpEncoder {
        return Driver::detect() === Driver::IMAGICK
            ? new Imagick\Encoders\WebpEncoder($quality, $strip)
            : new Gd\Encoders\WebpEncoder($quality, $strip);
    }

    /**
     * @param \GdImage|\Imagick|\Intervention\Image\Interfaces\ImageInterface|\SplFileInfo|\Stringable|string  $input
     *
     * @return ImageInterface
     */
    public static function from(
        \GdImage|\Imagick|ImageInterface|SplFileInfo|Stringable|string $input,
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
        $driver = Driver::detect();

        if ($is) {
            return $driver === $is;
        }

        return $driver;
    }

    public static function getImageProcessor(): ImageManager
    {
        return Image::$imageProcessor ??= new ImageManager(
            driver: match (Driver::detect()) {
                Driver::IMAGICK => new Imagick\Driver(),
                Driver::GD      => new Gd\Driver(),
            },
        );
    }

    public static function getPixelMapClamp(): int
    {
        return PixelMapLimits::clamp();
    }

    public static function setPixelMapClamp(int $pixelMapClamp): void
    {
        PixelMapLimits::setClamp($pixelMapClamp);
    }
}
