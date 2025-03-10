<?php

namespace Support\Image;

use Intervention\Image\Interfaces\ImageInterface;
use function Support\num_gcd;
use const Support\AUTO;
use LogicException;
use InvalidArgumentException;

/**
 * https://css-tricks.com/almanac/properties/a/aspect-ratio/
 */
final readonly class Aspect
{
    public int $divisor;

    public int $width;

    public int $height;

    public readonly Orientation $orientation;

    public function __construct( string|ImageInterface $source )
    {
        if ( $source instanceof ImageInterface ) {
            $this->divisor = num_gcd( $source->width(), $source->height() );
            $this->width   = $source->width()  / $this->divisor;
            $this->height  = $source->height() / $this->divisor;
        }
        elseif ( \is_readable( $source ) ) {
            [$width, $height] = \getimagesize( $source )
                    ?: throw new LogicException( 'Unable to get image size from '.$source );

            $this->divisor = num_gcd( $width, $height );
            $this->width   = $width  / $this->divisor;
            $this->height  = $height / $this->divisor;
        }
        else {
            $message = 'Unable to get image size from: '.\print_r( $source, true );
            throw new InvalidArgumentException( $message );
        }

        $this->orientation = Orientation::from( $this->width, $this->height );
    }

    public static function from( string|ImageInterface $source ) : self
    {
        return new self( $source );
    }

    public function getRatio( string $separator = '/' ) : string
    {
        return $this->width.$separator.$this->height;
    }

    public function getFloat( ?Orientation $orientation = AUTO ) : float
    {
        $num = match ( $orientation ?? $this->orientation ) {
            Orientation::PORTRAIT => $this->height / $this->width,
            default               => $this->width  / $this->height,
        };

        return (float) \number_format( $num, 4 );
    }

    /**
     * @param int $edge
     *
     * @return array{0: int, 1: int}
     */
    public function scaleShortest( int $edge ) : array
    {
        $longEdge = (int) \round( $edge * $this->getFloat() );

        return $this->orientation === Orientation::LANDSCAPE
                ? [$longEdge, $edge]
                : [$edge, $longEdge];
    }

    /**
     * @param int $edge
     *
     * @return array{0: int, 1: int}
     */
    public function scaleLongest( int $edge ) : array
    {
        $shortest = (int) \round( $edge / $this->getFloat() );

        return $this->orientation === Orientation::LANDSCAPE
                ? [$edge, $shortest]
                : [$shortest, $edge];
    }

    /**
     * @param int $width
     *
     * @return array{0: int, 1: int}
     */
    public function scaleWidth( int $width ) : array
    {
        if ( $this->orientation === Orientation::SQUARE ) {
            $height = $width;
        }
        else {
            $height = (int) \round( $width * $this->getFloat( Orientation::PORTRAIT ) );
        }

        return [$width, $height];
    }

    /**
     * @param int $height
     *
     * @return array{0: int, 1: int}
     */
    public function scaleHeight( int $height ) : array
    {
        if ( $this->orientation === Orientation::SQUARE ) {
            $width = $height;
        }
        else {
            $width = (int) \round( $height * $this->getFloat( Orientation::LANDSCAPE ) );
        }

        return [$width, $height];
    }
}
