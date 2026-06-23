<?php

declare(strict_types = 1);

namespace Northrook\Core\Image;

use LogicException;

enum Driver
{
    case GD;
    case IMAGICK;

    /**
     * Detect the {@see \Intervention\Image\ImageManager} driver type.
     *
     * - Checks if the `imagick` extension is loaded.
     * - Check is only performed once.
     * - Ensures either `gd` or `imagick` is available.
     *
     * @return Driver
     */
    public static function detect(): Driver
    {
        static $driver;

        if (isset($driver)) {
            return $driver;
        }

        if (! \extension_loaded('gd') && ! \extension_loaded('imagick')) {
            throw new LogicException('The `gd` or `imagick` extension must be loaded.');
        }

        return $driver = \extension_loaded('imagick') ? Driver::IMAGICK : Driver::GD;
    }

    public static function isImagick(): bool
    {
        return Driver::detect() === Driver::IMAGICK;
    }

    public static function isGD(): bool
    {
        return Driver::detect() === Driver::GD;
    }
}
