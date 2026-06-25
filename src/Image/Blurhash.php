<?php

declare(strict_types=1);

namespace Northrook\Core\Image;

use Intervention\Image\Interfaces\ImageInterface;
use InvalidArgumentException;
use LogicException;
use Northrook\Core\Image;
use SplFileInfo;
use Stringable;

/**
 * Blurhash encode/decode.
 *
 * Based heavily on [php-blurhash](https://github.com/kornrunner/php-blurhash) package by Boris Momčilović.
 *
 * **Usage context:** {@see encode()} runs during image optimisation (cold path); its string
 * output is cached. {@see decode()} and the {@see decodeToImage()} / {@see decodeToDataUri()}
 * helpers may run on page render (warm path) but their output is also cached in production.
 * Prefer caching at the call site over micro-optimising internals here.
 *
 * @author     Martin Nielsen <mn@northrook.com>
 *
 * @copyright  Copyright (c) 2026 Martin Nielsen
 * @copyright  Copyright (c) 2019 Boris Momčilović
 * @copyright  Copyright (c) 2018 Wolt Enterprises
 */
final class Blurhash
{
    private const int BASE = 83;

    // @formatter:off
    private const string BASE_83_CHARSET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_{|}~';
    // @formatter:on

    /**
     * Encode a blurhash string from an image or pixel map.
     *
     * Called while processing an image for optimization; the returned hash is cached and rarely recomputed.
     *
     * Acceptable to be slow relative to decode.
     *
     * Cost is dominated by {@see Image::getPixelMap()} when `$source` is not already an array,
     * then a DCT over the map — roughly O(componentGrid × mapWidth × mapHeight).
     *
     * Pass a pre-built map with `sourceIsLinear: true` only when the caller already holds linear RGB samples and wants to skip re-sampling.
     *
     * Pixels may include an optional alpha channel `[r, g, b, a]` (0–255). Blurhash is RGB-only, so transparent samples are composited onto `$matte` in linear space before encoding. Pass `matte: null` to ignore alpha (transparent pixels contribute black).
     *
     * @param array<int, array<int, int[]>>|ImageInterface|SplFileInfo|string|Stringable  $source
     * @param positive-int                                                                $resolution
     * @param null|array{0: int<1,9>, 1:int<1,9>}                                         $components
     * @param bool                                                                        $prefixSize
     * @param bool                                                                        $sourceIsLinear
     * @param null|array{0: int, 1: int, 2: int}                                          $matte  sRGB background for alpha compositing; defaults to white.
     *
     * @return string
     */
    public static function encode(
        array|SplFileInfo|Stringable|string|ImageInterface $source,
        int $resolution = 64,
        null|array $components = null,
        bool $prefixSize = true,
        bool $sourceIsLinear = false,
        null|array $matte = [255, 255, 255],
    ): string {
        if ($sourceIsLinear && ! \is_array($source)) {
            throw new InvalidArgumentException(\sprintf(
                'sourceIsLinear requires a pre-processed pixel map array; got %s.',
                \get_debug_type($source),
            ));
        }

        $map = \is_array($source)
            ? $source
            : Image::getPixelMap(
                $source,
                $resolution,
                PixelMapLimits::maxPixels(),
            );

        [$width, $height] = self::mapDimensions($map);

        [$gridY, $gridX] = match (true) {
            \is_array($components) => self::guardComponentGrid($components),
            default => self::componentRatio($width, $height),
        };

        if ($matte !== null) {
            $matte = self::guardMatte($matte);

            if (self::mapHasAlpha($map)) {
                $map = self::flattenAlpha($map, $matte, $sourceIsLinear);
            }
        }

        $map = $sourceIsLinear ? $map : self::linearMap($map, $width, $height);

        $grid_y = (int) Calc::clamp($gridY, 1, 9);
        $grid_x = (int) Calc::clamp($gridX, 1, 9);

        $components = [];
        $scale      = 1 / ( $width * $height );

        for ($cy = 0; $cy < $grid_y; $cy++) {
            for ($cx = 0; $cx < $grid_x; $cx++) {
                $normalisation = $cx === 0 && $cy === 0 ? 1 : 2;
                $r             = $g = $b = 0;
                for ($i = 0; $i < $width; $i++) {
                    for ($j = 0; $j < $height; $j++) {
                        $color = $map[$j][$i];
                        $basis = $normalisation
                        * \cos(( M_PI * $i * $cx ) / $width)
                        * \cos(( M_PI * $j * $cy ) / $height);

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

        $dc_value = self::dc_encode(\array_shift($components) ?: []);

        $max_ac = 0;

        foreach ($components as $component) {
            $component[] = $max_ac;
            $max_ac      = \max($component);
        }

        $quant_max_ac   = (int) Calc::clamp(\floor(( $max_ac * 166 ) - 0.5), 0, 82);
        $ac_norm_factor = ( $quant_max_ac + 1 ) / 166;

        $ac_values = [];

        foreach ($components as $component) {
            $ac_values[] = self::ac_encode($component, $ac_norm_factor);
        }

        $blurhash = self::base83_encode($grid_x - 1 + ( ( $grid_y - 1 ) * 9 ), 1);
        $blurhash .= self::base83_encode($quant_max_ac, 1);
        $blurhash .= self::base83_encode($dc_value, 4);

        foreach ($ac_values as $ac_value) {
            $blurhash .= self::base83_encode((int) $ac_value, 2);
        }

        if ($prefixSize) {
            return "<{$width}:{$height}>{$blurhash}";
        }

        return $blurhash;
    }

    /**
     * Decode a blurhash string to an RGB pixel map.
     *
     * **Potentially warm path** — may run when serving a placeholder before the real image
     * loads. In production the decoded map (or a {@see decodeToDataUri()} result derived from
     * it) is almost always cached; treat uncached calls as the exception when profiling.
     *
     * Cost is O(componentGrid × outputWidth × outputHeight), capped by {@see setMaxPixels()}.
     * Cache the return value or a {@see decodeToImage()} / data-URI derivative rather than
     * tuning loop internals unless profiling shows a cache miss storm.
     *
     * @param string    $blurhash
     * @param null|int  $width   With a size prefix: omit both to use embedded dimensions, pass one to derive the other, or pass both for an explicit output size (e.g. `...$aspect->scaleShortest( $edge )`).
     * @param null|int  $height
     * @param float     $punch
     *
     * @return array<int, array<int, int[]>>
     */
    public static function decode(
        string $blurhash,
        null|int $width = null,
        null|int $height = null,
        float $punch = 1.0,
    ): array {
        $length = \strlen($blurhash);

        if ($length < 6) {
            throw new InvalidArgumentException(\sprintf('Blurhash must be at least 6 characters; got %d.', $length));
        }

        [$blurhash, $width, $height] = self::resolveDecodeDimensions(
            $blurhash,
            $width,
            $height,
        );

        $size_info = self::base83_decode($blurhash[0]);
        $size_y    = \intdiv($size_info, 9) + 1;
        $size_x    = ( $size_info % 9 ) + 1;

        $length          = \strlen($blurhash);
        $expected_length = (int) ( 4 + ( 2 * $size_y * $size_x ) );

        if ($length !== $expected_length) {
            throw new InvalidArgumentException(\sprintf(
                'Blurhash length (%d) does not match component grid (%d×%d expects %d characters).',
                $length,
                $size_x,
                $size_y,
                $expected_length,
            ));
        }

        $colors = [
            self::dc_decode(self::base83_decode(\substr($blurhash, 2, 4))),
        ];

        $quant_max_ac_component = self::base83_decode($blurhash[1]);
        $max_value              = ( $quant_max_ac_component + 1 ) / 166;

        for ($i = 1; $i < ( $size_x * $size_y ); $i++) {
            $value      = self::base83_decode(\substr($blurhash, 4 + ( $i * 2 ), 2));
            $colors[$i] = self::ac_decode($value, $max_value * $punch);
        }

        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $r = $g = $b = 0;
                for ($j = 0; $j < $size_y; $j++) {
                    for ($i = 0; $i < $size_x; $i++) {
                        $color = $colors[$i + ( $j * $size_x )];
                        $basis = \cos(( M_PI * $x * $i ) / $width) * \cos(( M_PI * $y * $j ) / $height);

                        $r += $color[0] * $basis;
                        $g += $color[1] * $basis;
                        $b += $color[2] * $basis;
                    }
                }

                $row[] = self::rgb($r, $g, $b);
            }
            $pixels[] = $row;
        }

        return $pixels;
    }

    /**
     * Decode to a PNG data URI — {@see decode()} plus GD/Imagick image creation and PNG encode.
     *
     * Heavier than {@see decode()} alone. Cache the returned string in production.
     *
     * @param array<int, array<int, int[]>>|string  $blurhash
     *
     * @throws LogicException
     */
    public static function decodeToDataUri(
        string|array $blurhash,
        null|int $width = null,
        null|int $height = null,
    ): string {
        return Blurhash::decodeToImage(
            $blurhash,
            width: $width,
            height: $height,
        )
            ->encode(Image::pngEncoder())
            ->toDataUri();
    }

    /**
     *
     * Resolve an {@see ImageInterface} from an encoded `blurhash` string or pixel map.
     *
     * - Heavier than {@see decode()} alone.
     * - Cache the result or a derived encoding in production.
     *
     * @param array<int, array<int, int[]>>|string  $input
     *
     * @throws LogicException
     */
    public static function decodeToImage(
        string|array $input,
        null|int $width = null,
        null|int $height = null,
    ): ImageInterface {
        if (\is_string($input)) {
            $map = self::decode($input, width: $width, height: $height);
        } else {
            if ($width !== null || $height !== null) {
                throw new InvalidArgumentException(
                    '`$width` and `$height` only apply when decoding a blurhash string.',
                );
            }

            self::mapDimensions($input);
            $map = $input;
        }

        return match (Image::driver()) {
            Driver::IMAGICK => self::decodeToImageImagick($map),
            Driver::GD      => self::decodeToImageGd($map),
        };
    }

    /**
     * Upper bound on width × height for decode output and encode input maps.
     *
     * Primary guard against expensive decode on cache misses. Lower only with care — it
     * changes the maximum placeholder resolution callers can request.
     *
     * @return int<32, 16777215>
     */
    public static function maxPixels(): int
    {
        return PixelMapLimits::maxPixels();
    }

    /**
     * @param int<32, 16777215>  $maxPixels
     */
    public static function setMaxPixels(
        int $maxPixels,
    ): void {
        PixelMapLimits::setMaxPixels($maxPixels);
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private static function resolveDecodeDimensions(
        string $blurhash,
        null|int $width = null,
        null|int $height = null,
    ): array {
        if ($blurhash !== '' && $blurhash[0] === '<') {
            [$blurhash, $prefixWidth, $prefixHeight] = self::parseSizePrefix($blurhash);

            if ($width !== null && $height !== null) {
                self::guardPixelBudget($width, $height);

                return [$blurhash, $width, $height];
            }

            if ($width === null && $height === null) {
                $width  = $prefixWidth;
                $height = $prefixHeight;
            } elseif ($width !== null) {
                $height = (int) \round(( $width * $prefixHeight ) / $prefixWidth);
            } else {
                $width = (int) \round(( $height * $prefixWidth ) / $prefixHeight);
            }

            self::guardPixelBudget($width, $height);

            return [$blurhash, $width, $height];
        }

        if ($width === null || $height === null) {
            throw new InvalidArgumentException(
                'Blurhash decode without a size prefix requires both width and height.',
            );
        }

        self::guardPixelBudget($width, $height);

        return [$blurhash, $width, $height];
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private static function parseSizePrefix(
        string $blurhash,
    ): array {
        if (! \str_contains($blurhash, '>')) {
            throw new InvalidArgumentException(
                'Blurhash size prefix is missing a closing ">". Expected format: "<width:height>".',
            );
        }

        [$sizes, $blurhash] = \explode('>', $blurhash, 2);
        $parts = \explode(':', \trim($sizes, '<>'), 2);

        if (
            \count($parts) !== 2
            || ! \ctype_digit($parts[0])
            || ! \ctype_digit($parts[1])
            || (int) $parts[0] < 1
            || (int) $parts[1] < 1
        ) {
            throw new InvalidArgumentException(
                'Blurhash size prefix must be "<width:height>" with positive integers, e.g. "<32:18>".',
            );
        }

        return [$blurhash, (int) $parts[0], (int) $parts[1]];
    }

    /**
     * @param array<int, array<int, int[]>>  $map
     */
    private static function decodeToImageGd(
        array $map,
    ): ImageInterface {
        if (! \extension_loaded('gd')) {
            throw new LogicException('GD extension is not loaded.');
        }

        $gd = imagecreatefromstring(self::pixelMapToPpm($map));

        if ($gd === false) {
            throw new LogicException('Failed to create GD image from pixel map buffer.');
        }

        return Image::from($gd);
    }

    /**
     * @param array<int, array<int, int[]>>  $map
     *
     * @throws LogicException
     */
    private static function decodeToImageImagick(
        array $map,
    ): ImageInterface {
        if (! \extension_loaded('imagick')) {
            throw new LogicException('Imagick extension is not loaded.');
        }

        $imagick = new \Imagick();
        try {
            $imagick->readImageBlob(self::pixelMapToPpm($map));
        } catch (\Throwable $exception) {
            throw new LogicException(
                'Failed to create Imagick image from pixel map buffer.',
                previous: $exception,
            );
        }

        return Image::from($imagick);
    }

    /**
     * @param array<int, array<int, int[]>>  $map
     */
    private static function pixelMapToPpm(
        array $map,
    ): string {
        [$width, $height] = self::mapDimensions($map);

        return \sprintf("P6\n%d %d\n255\n", $width, $height) . self::pixelMapToRgbBuffer($map);
    }

    /**
     * @param array<int, array<int, int[]>>  $map
     */
    private static function pixelMapToRgbBuffer(
        array $map,
    ): string {
        $buffer = '';

        foreach ($map as $row) {
            foreach ($row as $pixel) {
                $buffer .= \pack('C3', $pixel[0], $pixel[1], $pixel[2]);
            }
        }

        return $buffer;
    }

    private static function guardPixelBudget(
        int $width,
        int $height,
        bool $isMap = false,
    ): void {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException(
                $isMap
                    ? \sprintf('Blurhash map must be at least 1×1; got %d×%d.', $width, $height)
                    : 'Blurhash decode requires positive width and height, or a hash with a size prefix (e.g. "<32:18>").',
            );
        }

        $pixels = $width * $height;

        if ($pixels > PixelMapLimits::maxPixels()) {
            throw new InvalidArgumentException(\sprintf(
                $isMap
                    ? 'Blurhash map exceeds pixel budget: %d×%d (%d pixels) exceeds limit of %d.'
                    : 'Blurhash decode output exceeds pixel budget: %d×%d (%d pixels) exceeds limit of %d.',
                $width,
                $height,
                $pixels,
                PixelMapLimits::maxPixels(),
            ));
        }
    }

    /**
     * @param array{0: int, 1: int}  $components
     *
     * @return array{0: int, 1: int}
     */
    private static function guardComponentGrid(
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
     * @param array<int, array<int, int[]>>  $map
     *
     * @return array{0: int, 1: int}
     */
    private static function mapDimensions(
        array $map,
    ): array {
        self::guardMapShape($map);

        $width  = \count($map[0]);
        $height = \count($map);

        self::guardPixelBudget($width, $height, isMap: true);

        return [$width, $height];
    }

    /**
     * @param array<int, array<int, int[]>>  $map
     */
    private static function guardMapShape(
        array $map,
    ): void {
        if ($map === [] || ! \array_is_list($map)) {
            throw new InvalidArgumentException(
                'Blurhash map must be a non-empty list of rows.',
            );
        }

        $firstRow = $map[0];

        if (! \is_array($firstRow) || ! \array_is_list($firstRow)) {
            throw new InvalidArgumentException(
                'Blurhash map row 0 must be a list of RGB values.',
            );
        }

        $width = \count($firstRow);

        if ($width < 1) {
            throw new InvalidArgumentException(
                'Blurhash map rows must not be empty.',
            );
        }

        self::guardRgbPixel($firstRow[0], 0, 0);

        for ($col = 1; $col < $width; $col++) {
            self::guardRgbPixel($firstRow[$col], 0, $col);
        }

        $height = \count($map);

        for ($row = 1; $row < $height; $row++) {
            $mapRow = $map[$row];

            if (! \is_array($mapRow) || ! \array_is_list($mapRow)) {
                throw new InvalidArgumentException(\sprintf(
                    'Blurhash map row %d must be a list of RGB values.',
                    $row,
                ));
            }

            $rowWidth = \count($mapRow);

            if ($rowWidth !== $width) {
                throw new InvalidArgumentException(\sprintf(
                    'Blurhash map rows must be rectangular; expected width %d, row %d has %d.',
                    $width,
                    $row,
                    $rowWidth,
                ));
            }

            for ($col = 0; $col < $width; $col++) {
                self::guardRgbPixel($mapRow[$col], $row, $col);
            }
        }
    }

    private static function guardRgbPixel(
        mixed $pixel,
        int $row,
        int $col,
    ): void {
        if (! \is_array($pixel) || ! \array_is_list($pixel) || \count($pixel) < 3) {
            throw new InvalidArgumentException(\sprintf(
                'Blurhash map pixel at [%d][%d] must be an RGB list [r, g, b] or RGBA [r, g, b, a].',
                $row,
                $col,
            ));
        }

        for ($channel = 0; $channel < 3; $channel++) {
            if (! \is_int($pixel[$channel]) && ! \is_float($pixel[$channel])) {
                throw new InvalidArgumentException(\sprintf(
                    'Blurhash map pixel at [%d][%d] channel %d must be numeric.',
                    $row,
                    $col,
                    $channel,
                ));
            }
        }

        if (\count($pixel) >= 4 && ! \is_int($pixel[3]) && ! \is_float($pixel[3])) {
            throw new InvalidArgumentException(\sprintf(
                'Blurhash map pixel at [%d][%d] alpha must be numeric.',
                $row,
                $col,
            ));
        }

        if (\count($pixel) >= 4 && ( $pixel[3] < 0 || $pixel[3] > 255 )) {
            throw new InvalidArgumentException(\sprintf(
                'Blurhash map pixel at [%d][%d] alpha must be between 0 and 255; got %s.',
                $row,
                $col,
                $pixel[3],
            ));
        }
    }

    /**
     * @param array<int, array<int, int[]|float[]>>  $map
     */
    private static function mapHasAlpha(
        array $map,
    ): bool {
        foreach ($map as $row) {
            foreach ($row as $pixel) {
                if (\count($pixel) >= 4) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array{0: int|float, 1: int|float, 2: int|float}  $matte
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function guardMatte(
        array $matte,
    ): array {
        if (\count($matte) < 3) {
            throw new InvalidArgumentException(
                'Blurhash matte must be an sRGB triplet [r, g, b].',
            );
        }

        $channels = [];

        foreach ($matte as $channel) {
            if (! \is_int($channel) && ! \is_float($channel)) {
                throw new InvalidArgumentException(
                    'Blurhash matte channels must be numeric.',
                );
            }

            $value = (int) $channel;

            if ($value < 0 || $value > 255) {
                throw new InvalidArgumentException(\sprintf(
                    'Blurhash matte channels must be between 0 and 255; got %d.',
                    $value,
                ));
            }

            $channels[] = $value;
        }

        return [$channels[0], $channels[1], $channels[2]];
    }

    /**
     * Composite RGBA samples onto an sRGB matte in linear space.
     *
     * @param array<int, array<int, int[]|float[]>>  $map
     * @param array{0: int, 1: int, 2: int}          $matte
     *
     * @return array<int, array<int, list<int>|list<float>>>
     */
    private static function flattenAlpha(
        array $map,
        array $matte,
        bool $pixelsAreLinear,
    ): array {
        $matteLinear = [
            self::colorLinear($matte[0]),
            self::colorLinear($matte[1]),
            self::colorLinear($matte[2]),
        ];

        $flattened = [];

        foreach ($map as $row) {
            $line = [];

            foreach ($row as $pixel) {
                if (\count($pixel) < 4) {
                    $line[] = [$pixel[0], $pixel[1], $pixel[2]];
                    continue;
                }

                $alpha = $pixel[3] / 255;
                $channels = $pixelsAreLinear
                    ? [(float) $pixel[0], (float) $pixel[1], (float) $pixel[2]]
                    : [
                        self::colorLinear($pixel[0]),
                        self::colorLinear($pixel[1]),
                        self::colorLinear($pixel[2]),
                    ];

                $composited = [];

                for ($channel = 0; $channel < 3; $channel++) {
                    $linear = ( $channels[$channel] * $alpha ) + ( $matteLinear[$channel] * ( 1 - $alpha ) );
                    $composited[] = $pixelsAreLinear ? $linear : self::color_rgb($linear);
                }

                $line[] = $composited;
            }

            $flattened[] = $line;
        }

        return $flattened;
    }

    /**
     * @param array<int, array<int, array<int|float>>>  $map
     *
     * @return array<int, array<int, float[]>>
     */
    private static function linearMap(
        array $map,
        int $width,
        int $height,
    ): array {
        $linear_map = [];
        for ($y = 0; $y < $height; $y++) {
            $line = [];
            for ($x = 0; $x < $width; $x++) {
                $pixel  = $map[$y][$x];
                $line[] = [
                    self::colorLinear($pixel[0]),
                    self::colorLinear($pixel[1]),
                    self::colorLinear($pixel[2]),
                ];
            }
            $linear_map[] = $line;
        }
        return $linear_map;
    }

    /**
     * @param int  $width
     * @param int  $height
     *
     * @return array{0: int, 1: int}
     */
    private static function componentRatio(
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

        $width  = (int) Calc::clamp((int) \round($edge * $ratio) + 1, 1, 9);
        $height = (int) Calc::clamp((int) \round($edge / $ratio) + 1, 1, 9);

        return $orientation === Orientation::LANDSCAPE ? [$width, $height] : [$height, $width];
    }

    private static function colorLinear(
        float|int $value,
    ): float {
        $value = (float) $value / 255;
        return $value <= 0.040_45 ? $value / 12.92 : \pow(( $value + 0.055 ) / 1.055, 2.4);
    }

    /**
     * @param float|int  $r
     * @param float|int  $g
     * @param float|int  $b
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(
        float|int $r,
        float|int $g,
        float|int $b,
    ): array {
        return [
            self::color_rgb($r),
            self::color_rgb($g),
            self::color_rgb($b),
        ];
    }

    private static function color_rgb(
        float $value,
    ): int {
        $normalized = Calc::clamp($value, 0, 1);
        $result     = $normalized <= 0.003_130_8
            ? (int) \round(( $normalized * 12.92 * 255 ) + 0.5)
            : (int) \round(( ( ( 1.055 * \pow($normalized, 1 / 2.4) ) - 0.055 ) * 255 ) + 0.5);

        return (int) Calc::clamp($result, 0, 255);
    }

    /**
     * @param int[]  $value
     *
     * @return int
     */
    private static function dc_encode(
        array $value,
    ): int {
        $rounded_r = self::color_rgb($value[0]);
        $rounded_g = self::color_rgb($value[1]);
        $rounded_b = self::color_rgb($value[2]);
        return ( $rounded_r << 16 ) + ( $rounded_g << 8 ) + $rounded_b;
    }

    /**
     * @param int  $value
     *
     * @return float[]
     */
    private static function dc_decode(
        int $value,
    ): array {
        return [
            self::colorLinear($value >> 16),
            self::colorLinear(( $value >> 8 ) & 255),
            self::colorLinear($value & 255),
        ];
    }

    /**
     * @param float[]  $value
     * @param float    $max_value
     *
     * @return float
     */
    private static function ac_encode(
        array $value,
        float $max_value,
    ): float {
        $quant_r = self::quantise($value[0] / $max_value);
        $quant_g = self::quantise($value[1] / $max_value);
        $quant_b = self::quantise($value[2] / $max_value);
        return ( $quant_r * 19 * 19 ) + ( $quant_g * 19 ) + $quant_b;
    }

    /**
     * @param int    $value
     * @param float  $max_value
     *
     * @return float[]
     */
    private static function ac_decode(
        int $value,
        float $max_value,
    ): array {
        $quant_r = \intdiv($value, 19 * 19);
        $quant_g = \intdiv($value, 19) % 19;
        $quant_b = $value % 19;

        return [
            self::signPow(( $quant_r - 9 ) / 9, 2) * $max_value,
            self::signPow(( $quant_g - 9 ) / 9, 2) * $max_value,
            self::signPow(( $quant_b - 9 ) / 9, 2) * $max_value,
        ];
    }

    private static function quantise(float $value): float
    {
        return Calc::clamp(\floor(( self::signPow($value, 0.5) * 9 ) + 9.5), 0, 18);
    }

    private static function signPow(
        float $base,
        float $exp,
    ): float {
        $sign = $base <=> 0;
        return $sign * \pow(\abs($base), $exp);
    }

    private static function base83_encode(
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

    private static function base83_decode(
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
}
