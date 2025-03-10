<?php

declare(strict_types=1);

namespace Support\Image;

use InvalidArgumentException;

enum Orientation
{
    case LANDSCAPE;
    case PORTRAIT;
    case SQUARE;

    public static function from( int $width, int $height ) : Orientation
    {
        return match ( true ) {
            $width > $height   => self::LANDSCAPE,
            $width < $height   => self::PORTRAIT,
            $width === $height => self::SQUARE,
            default            => throw new InvalidArgumentException(
                "Invalid image dimensions.\n'w{$width}' x 'h{$height}' provided.",
            ),
        };
    }
}
