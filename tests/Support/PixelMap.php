<?php

declare(strict_types = 1);

namespace Northrook\Core\Tests\Support;

final class PixelMap
{
    /**
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
}
