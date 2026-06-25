<?php

declare(strict_types = 1);

namespace Northrook\Core\Image;

use InvalidArgumentException;

enum Orientation
{
    case LANDSCAPE;
    case PORTRAIT;
    case SQUARE;

    /**
     * @param int  $width
     * @param int  $height
     *
     * @return \Northrook\Core\Image\Orientation
     * @throws \InvalidArgumentException if either dimension is less than 1.
     */
    public static function from(
        int $width,
        int $height,
    ): Orientation {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException(
                \sprintf('Image dimensions must be positive integers; got %d×%d.', $width, $height),
            );
        }

        if (( $width * $height ) > ( \PHP_INT_MAX / 2 )) {
            throw new InvalidArgumentException(
                \sprintf('Image dimensions are too large; got %d×%d.', $width, $height),
            );
        }

        if ($width > $height) {
            return self::LANDSCAPE;
        }

        if ($width < $height) {
            return self::PORTRAIT;
        }

        return self::SQUARE;
    }
}
