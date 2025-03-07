<?php

declare(strict_types=1);

namespace Support;

use Intervention\Image\Encoders\{JpegEncoder, PngEncoder, WebpEncoder};
use Intervention\Image\ImageManager;

use Intervention\Image\Drivers\{Gd, Imagick};
use Intervention\Image\Interfaces\ImageInterface;
use SplFileInfo;
use Stringable;

final class Image
{
    private static ImageManager $imageProcessor;

    /** @var 'gd'|'imagick' */
    private static string $imageDriver;

    /** @var 'gd'|'imagick' */
    public readonly string $driver;

    public function __construct()
    {
        $this->driver = $this->getDriver();
    }

    public static function pngEncoder() : PngEncoder
    {
        return Image::getDriver() === 'imagick'
                ? new Imagick\Encoders\PngEncoder()
                : new Gd\Encoders\PngEncoder();
    }

    public static function jpegEncoder() : JpegEncoder
    {
        return Image::getDriver() === 'imagick'
                ? new Imagick\Encoders\JpegEncoder()
                : new Gd\Encoders\JpegEncoder();
    }

    public static function webpEncoder() : WebpEncoder
    {
        return Image::getDriver() === 'imagick'
                ? new Imagick\Encoders\WebpEncoder()
                : new Gd\Encoders\WebpEncoder();
    }



    public static function from( string|SplFileInfo|Stringable|ImageInterface $input ) : ImageInterface
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
     * @return 'gd'|'imagick'
     */
    public static function getDriver() : string
    {
        return Image::$imageDriver ??= \extension_loaded( 'imagick' ) ? 'imagick' : 'gd';
    }

    public static function getImageProcessor() : ImageManager
    {
        return Image::$imageProcessor ??= new ImageManager(
            driver : Image::getDriver() === 'imagick'
                                 ? new Imagick\Driver()
                                 : new Gd\Driver(),
        );
    }
}
