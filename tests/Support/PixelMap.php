<?php

declare(strict_types=1);

namespace Northrook\Core\Tests\Support;

/**
 * Test-only pixel map fixtures. Not part of the library API.
 *
 * @internal
 */
final class PixelMap
{
    /**
     * Uniform solid-colour map for read-only blurhash test input.
     *
     * Rows intentionally share the same array instance — every row is
     * identical and callers must not mutate individual pixels. Use a
     * bespoke nested loop if you need independent, writable rows.
     *
     * @param int[] $rgb
     *
     * @return list<list<int[]>>
     */
    public static function solid(
        int $width,
        int $height,
        array $rgb,
    ): array {
        $row = \array_fill(0, $width, $rgb);

        return \array_fill(0, $height, $row);
    }

    /**
     * Horizontal and vertical RGB gradient for cross-implementation blurhash fixtures.
     *
     * @return list<list<int[]>>
     */
    public static function gradient(
        int $width,
        int $height,
    ): array {
        $map = [];

        for ($y = 0; $y < $height; $y++) {
            $row = [];

            for ($x = 0; $x < $width; $x++) {
                $row[] = [$x * 32, $y * 32, 128];
            }

            $map[] = $row;
        }

        return $map;
    }
}
