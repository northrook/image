<?php

declare(strict_types=1);

namespace Support\Image;

use Support\Image;
use Intervention\Image\Colors\Rgb\Color;
use Intervention\Image\Interfaces\ImageInterface;
use Stringable;
use SplFileInfo;
use InvalidArgumentException;
use function Support\num_clamp;
use const Support\INFER;

/**
 * Based heavily on [php-blurhash](https://github.com/kornrunner/php-blurhash) package by Boris Momčilović.
 *
 * @author     Martin Nielsen <mn@northrook.com>
 *
 * @copyright  Copyright (c) 2025 Martin Nielsen
 * @copyright  Copyright (c) 2019 Boris Momčilović
 * @copyright  Copyright (c) 2018 Wolt Enterprises
 */
final class Blurhash
{
    private const int BASE = 83;

    // @formatter:off
    private const array BASE_83_CHARSET = [
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'A', 'B', 'C', 'D',
        'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R',
        'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'a', 'b', 'c', 'd', 'e', 'f',
        'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't',
        'u', 'v', 'w', 'x', 'y', 'z', '#', '$', '%', '*', '+', ',', '-', '.',
        ':', ';', '=', '?', '@', '[', ']', '^', '_', '{', '|', '}', '~',
    ];
    // @formatter:on

    /**
     * @param array<int, array<int, int[]>>|ImageInterface|SplFileInfo|string|Stringable $source
     * @param int                                                                        $resolution
     * @param null|array{0: int<1,9>, 1:int<1,9>}|false                                  $ratio
     * @param bool                                                                       $prefixSize
     * @param bool                                                                       $sourceIsLinear
     *
     * @return string
     */
    public static function encode(
        array|SplFileInfo|Stringable|string|ImageInterface $source,
        int                                                $resolution = 64,
        null|false|array                                   $ratio = INFER,
        bool                                               $prefixSize = true,
        bool                                               $sourceIsLinear = false,
    ) : string {
        $map = \is_array( $source ) ? $source : Image::getPixelMap( $source, $resolution );

        [$width, $height] = self::mapDimensions( $map );

        [$y, $x] = match ( true ) {
            \is_array( $ratio ) => $ratio,
            $ratio === INFER    => self::componentRatio( $width, $height ),
            default             => [4, 4],
        };

        if ( $sourceIsLinear && ! \is_array( $source ) ) {
            throw new InvalidArgumentException(
                'Linear sources must be a pre-processed pixelMap.',
            );
        }

        $map = $sourceIsLinear ? $map : self::linearMap( $map );

        $grid_y = (int) num_clamp( $y, 1, 9 );
        $grid_x = (int) num_clamp( $x, 1, 9 );

        $components = [];
        $scale      = 1 / ( $width * $height );

        for ( $y = 0; $y < $grid_y; $y++ ) {
            for ( $x = 0; $x < $grid_x; $x++ ) {
                $normalisation = $x == 0 && $y == 0 ? 1 : 2;
                $r             = $g = $b = 0;
                for ( $i = 0; $i < $width; $i++ ) {
                    for ( $j = 0; $j < $height; $j++ ) {
                        $color = $map[$j][$i];
                        $basis = $normalisation
                                 * \cos( M_PI * $i * $x / $width )
                                 * \cos( M_PI * $j * $y / $height );

                        $r += $basis * $color[0];
                        $g += $basis * $color[1];
                        $b += $basis * $color[2];
                    }
                }

                $components[] = [
                    $r * $scale,
                    $g * $scale,
                    $b * $scale,
                ];
            }
        }

        $dc_value = self::dc_encode( \array_shift( $components ) ?: [] );

        $max_ac = 0;

        foreach ( $components as $component ) {
            $component[] = $max_ac;
            $max_ac      = \max( $component );
        }

        $quant_max_ac   = (int) num_clamp( \floor( $max_ac * 166 - 0.5 ), 0, 82 );
        $ac_norm_factor = ( $quant_max_ac + 1 ) / 166;

        $ac_values = [];

        foreach ( $components as $component ) {
            $ac_values[] = self::ac_encode( $component, $ac_norm_factor );
        }

        $blurhash = self::base83_encode( $grid_x - 1 + ( $grid_y - 1 ) * 9, 1 );
        $blurhash .= self::base83_encode( $quant_max_ac, 1 );
        $blurhash .= self::base83_encode( $dc_value, 4 );

        foreach ( $ac_values as $ac_value ) {
            $blurhash .= self::base83_encode( (int) $ac_value, 2 );
        }

        if ( $prefixSize ) {
            return "<{$width}:{$height}>{$blurhash}";
        }

        return $blurhash;
    }

    /**
     * @param string   $blurhash
     * @param null|int $width
     * @param null|int $height
     * @param float    $punch
     *
     * @return array<int, array<int, int[]>>
     */
    public static function decode(
        string $blurhash,
        ?int   $width = INFER,
        ?int   $height = INFER,
        float  $punch = 1.0,
    ) : array {
        if ( empty( $blurhash ) || \strlen( $blurhash ) < 6 ) {
            throw new InvalidArgumentException( 'Blurhash string must be at least 6 characters' );
        }

        if ( $blurhash[0] === '<' ) {
            [$sizes, $blurhash] = \explode( '>', $blurhash, 2 );

            if ( ! $width && ! $height ) {
                [$width, $height] = \explode( ':', \trim( $sizes, '<>' ), 2 );
            }
            elseif ( $width ) {
                [$y, $x] = \explode( ':', \trim( $sizes, '<>' ), 2 );
                $height  = (int) \round( $width * ( (int) $y ) / (int) $x );
            }

            unset( $sizes );
        }

        $size_info = self::base83_decode( $blurhash[0] );
        $size_y    = \intdiv( $size_info, 9 ) + 1;
        $size_x    = ( $size_info % 9 )       + 1;

        $length          = \strlen( $blurhash );
        $expected_length = (int) ( 4 + ( 2 * $size_y * $size_x ) );

        if ( $length !== $expected_length ) {
            $message = "Blurhash length mismatch: length is {$length} but it should be {$expected_length}";
            throw new InvalidArgumentException( $message );
        }

        $colors = [
            self::dc_decode(
                self::base83_decode( \substr( $blurhash, 2, 4 ) ),
            ),
        ];

        $quant_max_ac_component = self::base83_decode( $blurhash[1] );
        $max_value              = ( $quant_max_ac_component + 1 ) / 166;

        for ( $i = 1; $i < $size_x * $size_y; $i++ ) {
            $value      = self::base83_decode( \substr( $blurhash, 4 + $i * 2, 2 ) );
            $colors[$i] = self::ac_decode( $value, $max_value * $punch );
        }

        \assert( \is_int( $width ) && \is_int( $height ) );

        $pixels = [];
        for ( $y = 0; $y < $height; $y++ ) {
            $row = [];
            for ( $x = 0; $x < $width; $x++ ) {
                $r = $g = $b = 0;
                for ( $j = 0; $j < $size_y; $j++ ) {
                    for ( $i = 0; $i < $size_x; $i++ ) {
                        $color = $colors[$i + $j * $size_x];
                        $basis = \cos( ( M_PI * $x * $i ) / $width )
                                 * \cos( ( M_PI * $y * $j ) / $height );

                        $r += $color[0] * $basis;
                        $g += $color[1] * $basis;
                        $b += $color[2] * $basis;
                    }
                }

                $row[] = self::rgb( $r, $g, $b );
            }
            $pixels[] = $row;
        }

        return $pixels;
    }

    /**
     * @param array<int, array<int, int[]>>|string $blurhash
     * @param int                                  $resolution
     *
     * @return string
     */
    public static function decodeToDataUri(
        string|array $blurhash,
        int          $resolution = 64,
    ) : string {
        return Blurhash::decodeToImage( $blurhash, $resolution )
            ->encode( Image::pngEncoder() )
            ->toDataUri();
    }

    /**
     * @param array<int, array<int, int[]>>|string $blurhash
     * @param int                                  $resolution
     *
     * @return ImageInterface
     */
    public static function decodeToImage(
        string|array $blurhash,
        int          $resolution = 64,
    ) : ImageInterface {
        $map   = \is_string( $blurhash ) ? self::decode( $blurhash, $resolution ) : $blurhash;
        $image = Image::create( ...self::mapDimensions( $map ) );

        foreach ( $map as $height => $row ) {
            foreach ( $row as $width => $pixel ) {
                $image->drawPixel( $width, $height, new Color( ...$pixel ) );
            }
        }

        return $image;
    }

    /**
     * @param array<int, array<int, int[]>> $map
     *
     * @return array{0: int, 1: int}
     */
    private static function mapDimensions( array $map ) : array
    {
        \assert( \array_is_list( $map ) );
        \assert( \is_array( $map[0] ) && \array_is_list( $map[0] ) );

        return [
            \count( $map[0] ), // width
            \count( $map ),    // height
        ];
    }

    /**
     * @param array<int, array<int, int[]>> $map
     *
     * @return array<int, array<int, float[]>>
     */
    protected static function linearMap( array $map ) : array
    {
        [$width, $height] = self::mapDimensions( $map );

        $linear_map = [];
        for ( $y = 0; $y < $height; $y++ ) {
            $line = [];
            for ( $x = 0; $x < $width; $x++ ) {
                $pixel  = $map[$y][$x];
                $line[] = [
                    self::colorLinear( $pixel[0] ),
                    self::colorLinear( $pixel[1] ),
                    self::colorLinear( $pixel[2] ),
                ];
            }
            $linear_map[] = $line;
        }
        return $linear_map;
    }

    /**
     * @param int $width
     * @param int $height
     *
     * @return array{0: int, 1: int}
     */
    private static function componentRatio( int $width, int $height ) : array
    {
        $edge        = 4;
        $orientation = Orientation::from( $width, $height );
        $shortEdge   = \min( $width, $height );
        $longEdge    = \max( $width, $height );
        $ratio       = \round(
            match ( $orientation ) {
                Orientation::PORTRAIT => $shortEdge / $longEdge,
                default               => $longEdge  / $shortEdge,
            },
            3,
        );

        $width  = (int) num_clamp( (int) \round( $edge * $ratio ) + 1, 1, 9 );
        $height = (int) num_clamp( (int) \round( $edge / $ratio ) + 1, 1, 9 );

        return $orientation === Orientation::LANDSCAPE
                ? [$width, $height]
                : [$height, $width];
    }

    protected static function colorLinear( int $value ) : float
    {
        $value = $value / 255;
        return ( $value <= 0.040_45 )
                ? $value / 12.92
                : \pow( ( $value + 0.055 ) / 1.055, 2.4 );
    }

    /**
     * @param float|int $r
     * @param float|int $g
     * @param float|int $b
     *
     * @return array{0: int, 1: int, 2: int}
     */
    protected static function rgb(
        float|int $r,
        float|int $g,
        float|int $b,
    ) : array {
        return [
            self::color_rgb( $r ),
            self::color_rgb( $g ),
            self::color_rgb( $b ),
        ];
    }

    protected static function color_rgb( float $value ) : int
    {
        $normalized = num_clamp( $value, 0, 1 );
        $result     = ( $normalized <= 0.003_130_8 )
                ? (int) \round( $normalized * 12.92 * 255 + 0.5 )
                : (int) \round( ( 1.055 * \pow( $normalized, 1 / 2.4 ) - 0.055 ) * 255 + 0.5 );

        return (int) num_clamp( $result, 0, 255 );
    }

    /**
     * @param int[] $value
     *
     * @return int
     */
    protected static function dc_encode( array $value ) : int
    {
        $rounded_r = self::color_rgb( $value[0] );
        $rounded_g = self::color_rgb( $value[1] );
        $rounded_b = self::color_rgb( $value[2] );
        return ( $rounded_r << 16 ) + ( $rounded_g << 8 ) + $rounded_b;
    }

    /**
     * @param int $value
     *
     * @return float[]
     */
    protected static function dc_decode( int $value ) : array
    {
        return [
            self::colorLinear( $value >> 16 ),
            self::colorLinear( ( $value >> 8 ) & 255 ),
            self::colorLinear( $value & 255 ),
        ];
    }

    /**
     * @param float[] $value
     * @param float   $max_value
     *
     * @return float
     */
    protected static function ac_encode( array $value, float $max_value ) : float
    {
        $quant_r = self::quantise( $value[0] / $max_value );
        $quant_g = self::quantise( $value[1] / $max_value );
        $quant_b = self::quantise( $value[2] / $max_value );
        return $quant_r * 19 * 19 + $quant_g * 19 + $quant_b;
    }

    /**
     * @param int   $value
     * @param float $max_value
     *
     * @return float[]
     */
    protected static function ac_decode( int $value, float $max_value ) : array
    {
        $quant_r = \intdiv( $value, 19 * 19 );
        $quant_g = \intdiv( $value, 19 ) % 19;
        $quant_b = $value                % 19;

        return [
            self::signPow( ( $quant_r - 9 ) / 9, 2 ) * $max_value,
            self::signPow( ( $quant_g - 9 ) / 9, 2 ) * $max_value,
            self::signPow( ( $quant_b - 9 ) / 9, 2 ) * $max_value,
        ];
    }

    private static function quantise( float $value ) : float
    {
        return num_clamp( \floor( self::signPow( $value, 0.5 ) * 9 + 9.5 ), 0, 18 );
    }

    private static function signPow( float $base, float $exp ) : float
    {
        $sign = $base <=> 0;
        return $sign * \pow( \abs( $base ), $exp );
    }

    private static function base83_encode( int $value, int $length ) : string
    {
        if ( \intdiv( $value, self::BASE ** $length ) != 0 ) {
            throw new InvalidArgumentException( 'Specified length is too short to encode given value.' );
        }

        $result = '';
        for ( $i = 1; $i <= $length; $i++ ) {
            $digit = \intdiv( $value, self::BASE ** ( $length - $i ) ) % self::BASE;
            $result .= self::BASE_83_CHARSET[$digit];
        }
        return $result;
    }

    private static function base83_decode( string $hash ) : int
    {
        $result = 0;

        foreach ( \str_split( $hash ) as $char ) {
            $result = $result * self::BASE + (int) \array_search( $char, self::BASE_83_CHARSET, true );
        }
        return $result;
    }
}
