<?php

declare(strict_types=1);

namespace Support;

use Intervention\Image\ImageManager;

use Intervention\Image\Drivers\{Gd, Imagick};

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
