<?php

declare(strict_types=1);

namespace Northrook\Core\Image;

/**
 * @internal
 */
final class Calc
{
    private function __construct() {}

    /**
     * Calculate the greatest common divisor between `$a` and `$b`.
     */
    public static function gcd(
        int $a,
        int $b,
    ): int {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }

    public static function clamp(
        float|int $num,
        float|int $min,
        float|int $max,
    ): float|int {
        return \max($min, \min($num, $max));
    }
}
