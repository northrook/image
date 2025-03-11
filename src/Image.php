<?php

declare(strict_types=1);

namespace Support;

use Intervention\Image\Interfaces\{ImageInterface};
use Intervention\Image\Encoders\{JpegEncoder, PngEncoder, WebpEncoder};
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\{AbstractEncoder, Gd, Imagick};
use Northrook\Logger\Log;
use SplFileInfo;
use Stringable;
use Support\Image\{Aspect, Driver};

final class Image
{
    private static ImageManager $imageProcessor;

    private static Driver $imageDriver;

    /**
     * @param ImageInterface|list<list<int[]>>|SplFileInfo|string|Stringable $source
     * @param int                                                            $resolution [64]
     *
     * @return list<list<int[]>>
     */
    public static function getPixelMap(
        array|SplFileInfo|Stringable|string|ImageInterface $source,
        int                                                $resolution = 64,
    ) : array {
        if ( $resolution < 4 || $resolution > 128 ) {
            Log::warning(
                '{method} The resolution {provided} is outside the acceptable range of 4-128. Return value has been clamped.',
                ['method' => __METHOD__, 'provided' => $resolution],
            );
            $resolution = (int) num_clamp( $resolution, 4, 128 );
        }

        $image = $source instanceof ImageInterface ? $source : Image::from( $source );

        [$width, $height] = Aspect::from( $image )->scaleShortest( $resolution );

        $map    = [];
        $height = (int) \round( $image->height() / $height );
        $width  = (int) \round( $image->width() / $width );

        for ( $y = 0; $y < $image->height(); $y += $height ) {
            for ( $x = 0; $x < $image->width(); $x += $width ) {
                $map[$y][] = $image->pickColor( $x, $y )->toArray();
            }
        }

        return \array_values( $map );
    }

    public static function pngEncoder(
        bool $interlaced = false,
        bool $indexed = false,
    ) : PngEncoder {
        return Driver::isImagick()
                ? new Imagick\Encoders\PngEncoder( $interlaced, $indexed )
                : new Gd\Encoders\PngEncoder( $interlaced, $indexed );
    }

    public static function jpegEncoder(
        int   $quality = AbstractEncoder::DEFAULT_QUALITY,
        bool  $progressive = false,
        ?bool $strip = null,
    ) : JpegEncoder {
        return Driver::isImagick()
                ? new Imagick\Encoders\JpegEncoder( $quality, $progressive, $strip )
                : new Gd\Encoders\JpegEncoder( $quality, $progressive, $strip );
    }

    public static function webpEncoder(
        int   $quality = AbstractEncoder::DEFAULT_QUALITY,
        ?bool $strip = null,
    ) : WebpEncoder {
        return Driver::isImagick()
                ? new Imagick\Encoders\WebpEncoder( $quality, $strip )
                : new Gd\Encoders\WebpEncoder( $quality, $strip );
    }

    public static function from( mixed $input ) : ImageInterface
    {
        if ( $input instanceof Stringable ) {
            $input = $input->__toString();
        }
        return Image::getImageProcessor()->read( $input );
    }

    public static function create( int $width, int $height ) : ImageInterface
    {
        return Image::getImageProcessor()->create( $width, $height );
    }

    /**
     * @param null|Driver $is
     *
     * @return ($is is null ? Driver : bool)
     */
    public static function driver( ?Driver $is = null ) : Driver|bool
    {
        Image::$imageDriver ??= Driver::detect();

        if ( $is ) {
            return Image::$imageDriver === $is;
        }

        return Image::$imageDriver;
    }

    public static function getImageProcessor() : ImageManager
    {
        return Image::$imageProcessor ??= new ImageManager(
            driver : Driver::isImagick()
                                 ? new Imagick\Driver()
                                 : new Gd\Driver(),
        );
    }
}
