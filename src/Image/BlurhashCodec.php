<?php

declare(strict_types=1);

namespace Northrook\Core\Image;

use InvalidArgumentException;

/**
 * @internal
 */
final class BlurhashCodec
{
    private const int BASE = 83;

    // @formatter:off
    private const string BASE_83_CHARSET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_{|}~';
    // @formatter:on

    private function __construct() {}

    /**
     * @param array{0: int, 1: int}  $components
     *
     * @return array{0: int, 1: int}
     */
    public static function guardComponentGrid(
        array $components,
    ): array {
        [$y, $x] = $components;

        if ($y < 1 || $y > 9 || $x < 1 || $x > 9) {
            throw new InvalidArgumentException(\sprintf(
                'Blurhash component grid must be between 1×1 and 9×9; got %d×%d.',
                $y,
                $x,
            ));
        }

        return [$y, $x];
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function componentRatio(
        int $width,
        int $height,
    ): array {
        $edge        = 4;
        $orientation = Orientation::from($width, $height);
        $shortEdge   = \min($width, $height);
        $longEdge    = \max($width, $height);
        $ratio       = \round(match ($orientation) {
            Orientation::PORTRAIT => $shortEdge / $longEdge,
            default               => $longEdge / $shortEdge,
        }, 3);

        $width  = (int) Internal::clamp((int) \round($edge * $ratio) + 1, 1, 9);
        $height = (int) Internal::clamp((int) \round($edge / $ratio) + 1, 1, 9);

        return $orientation === Orientation::LANDSCAPE ? [$width, $height] : [$height, $width];
    }

    /**
     * @param int[]  $value
     */
    public static function dcEncode(
        array $value,
    ): int {
        $roundedR = BlurhashColor::colorRgb($value[0]);
        $roundedG = BlurhashColor::colorRgb($value[1]);
        $roundedB = BlurhashColor::colorRgb($value[2]);

        return ( $roundedR << 16 ) + ( $roundedG << 8 ) + $roundedB;
    }

    /**
     * @return float[]
     */
    public static function dcDecode(
        int $value,
    ): array {
        return [
            BlurhashColor::colorLinear($value >> 16),
            BlurhashColor::colorLinear(( $value >> 8 ) & 255),
            BlurhashColor::colorLinear($value & 255),
        ];
    }

    /**
     * @param float[]  $value
     */
    public static function acEncode(
        array $value,
        float $maxValue,
    ): float {
        $quantR = self::quantise($value[0] / $maxValue);
        $quantG = self::quantise($value[1] / $maxValue);
        $quantB = self::quantise($value[2] / $maxValue);

        return ( $quantR * 19 * 19 ) + ( $quantG * 19 ) + $quantB;
    }

    /**
     * @return float[]
     */
    public static function acDecode(
        int $value,
        float $maxValue,
    ): array {
        $quantR = \intdiv($value, 19 * 19);
        $quantG = \intdiv($value, 19) % 19;
        $quantB = $value % 19;

        return [
            self::signPow(( $quantR - 9 ) / 9, 2) * $maxValue,
            self::signPow(( $quantG - 9 ) / 9, 2) * $maxValue,
            self::signPow(( $quantB - 9 ) / 9, 2) * $maxValue,
        ];
    }

    public static function base83Encode(
        int $value,
        int $length,
    ): string {
        if (\intdiv($value, self::BASE ** $length) != 0) {
            throw new InvalidArgumentException(\sprintf(
                'Base83 value %d cannot be encoded in %d character(s).',
                $value,
                $length,
            ));
        }

        $result = '';

        for ($i = 1; $i <= $length; $i++) {
            $digit  = \intdiv($value, self::BASE ** ( $length - $i )) % self::BASE;
            $result .= self::BASE_83_CHARSET[$digit];
        }

        return $result;
    }

    public static function base83Decode(
        string $hash,
    ): int {
        $result = 0;

        foreach (\str_split($hash) as $char) {
            $index = \strpos(self::BASE_83_CHARSET, $char);

            if ($index === false) {
                throw new InvalidArgumentException(\sprintf(
                    "Blurhash contains invalid character '%s' (not in base83 alphabet).",
                    $char,
                ));
            }

            $result = ( $result * self::BASE ) + $index;
        }

        return $result;
    }

    private static function quantise(
        float $value,
    ): float {
        return Internal::clamp(\floor(( self::signPow($value, 0.5) * 9 ) + 9.5), 0, 18);
    }

    private static function signPow(
        float $base,
        float $exp,
    ): float {
        $sign = $base <=> 0;

        return $sign * \pow(\abs($base), $exp);
    }
}
