<?php

declare(strict_types=1);

namespace Northrook\Core\Image;

use Intervention\Image\Interfaces\ImageInterface;
use InvalidArgumentException;
use Northrook\Core\Image;
use SplFileInfo;
use Stringable;

/**
 * Blurhash encode/decode.
 *
 * Based heavily on [php-blurhash](https://github.com/kornrunner/php-blurhash) package by Boris Momčilović.
 *
 * **Encoded string:** By default {@see encode()} prefixes the base83 blurhash body with
 * `<mapWidth:mapHeight>` — the pixel-map grid size used during encoding (from
 * {@see Image::getPixelMap()}), not the original file dimensions. The prefix preserves
 * aspect ratio so {@see decode()} can derive output size: omit both dimensions to use the
 * embedded grid size, pass one edge to scale proportionally, or pass both for an explicit
 * output size. Set `$prefixSize` to false to emit the body only; {@see decode()} then
 * requires both width and height. This wire format is internal to this library and cached
 * strings in your stack — not standard blurhash and not intended for third-party hashes.
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
     * @param bool                                                                        $prefixSize  Embed `<mapWidth:mapHeight>` prefix; see class docblock.
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

        [$width, $height] = self::mapDimensions($map, $sourceIsLinear);

        [$gridY, $gridX] = match (true) {
            \is_array($components) => BlurhashCodec::guardComponentGrid($components),
            default => BlurhashCodec::componentRatio($width, $height),
        };

        if ($matte !== null) {
            $matte = BlurhashColor::guardMatte($matte);

            if (BlurhashColor::mapHasAlpha($map)) {
                $map = BlurhashColor::flattenAlpha($map, $matte, $sourceIsLinear);
            }
        }

        $map = $sourceIsLinear ? $map : BlurhashColor::linearMap($map, $width, $height);

        $gridY = (int) Internal::clamp($gridY, 1, 9);
        $gridX = (int) Internal::clamp($gridX, 1, 9);

        $componentValues = [];
        $scale           = 1 / ( $width * $height );

        for ($cy = 0; $cy < $gridY; $cy++) {
            for ($cx = 0; $cx < $gridX; $cx++) {
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

                $componentValues[] = [
                    $r * $scale,
                    $g * $scale,
                    $b * $scale,
                ];
            }
        }

        $dcValue = BlurhashCodec::dcEncode(\array_shift($componentValues) ?: []);

        $maxAc = 0;

        foreach ($componentValues as $component) {
            $component[] = $maxAc;
            $maxAc       = \max($component);
        }

        $quantMaxAc   = (int) Internal::clamp(\floor(( $maxAc * 166 ) - 0.5), 0, 82);
        $acNormFactor = ( $quantMaxAc + 1 ) / 166;

        $acValues = [];

        foreach ($componentValues as $component) {
            $acValues[] = BlurhashCodec::acEncode($component, $acNormFactor);
        }

        $blurhash = BlurhashCodec::base83Encode($gridX - 1 + ( ( $gridY - 1 ) * 9 ), 1);
        $blurhash .= BlurhashCodec::base83Encode($quantMaxAc, 1);
        $blurhash .= BlurhashCodec::base83Encode($dcValue, 4);

        foreach ($acValues as $acValue) {
            $blurhash .= BlurhashCodec::base83Encode((int) $acValue, 2);
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

        $sizeInfo = BlurhashCodec::base83Decode($blurhash[0]);
        $sizeY    = \intdiv($sizeInfo, 9) + 1;
        $sizeX    = ( $sizeInfo % 9 ) + 1;

        $length          = \strlen($blurhash);
        $expectedLength = (int) ( 4 + ( 2 * $sizeY * $sizeX ) );

        if ($length !== $expectedLength) {
            throw new InvalidArgumentException(\sprintf(
                'Blurhash length (%d) does not match component grid (%d×%d expects %d characters).',
                $length,
                $sizeX,
                $sizeY,
                $expectedLength,
            ));
        }

        $colors = [
            BlurhashCodec::dcDecode(BlurhashCodec::base83Decode(\substr($blurhash, 2, 4))),
        ];

        $quantMaxAcComponent = BlurhashCodec::base83Decode($blurhash[1]);
        $maxValue            = ( $quantMaxAcComponent + 1 ) / 166;

        for ($i = 1; $i < ( $sizeX * $sizeY ); $i++) {
            $value      = BlurhashCodec::base83Decode(\substr($blurhash, 4 + ( $i * 2 ), 2));
            $colors[$i] = BlurhashCodec::acDecode($value, $maxValue * $punch);
        }

        $pixels = [];

        for ($y = 0; $y < $height; $y++) {
            $row = [];

            for ($x = 0; $x < $width; $x++) {
                $r = $g = $b = 0;

                for ($j = 0; $j < $sizeY; $j++) {
                    for ($i = 0; $i < $sizeX; $i++) {
                        $color = $colors[$i + ( $j * $sizeX )];
                        $basis = \cos(( M_PI * $x * $i ) / $width) * \cos(( M_PI * $y * $j ) / $height);

                        $r += $color[0] * $basis;
                        $g += $color[1] * $basis;
                        $b += $color[2] * $basis;
                    }
                }

                $row[] = BlurhashColor::rgb($r, $g, $b);
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
     * Resolve an {@see ImageInterface} from an encoded `blurhash` string or pixel map.
     *
     * @param array<int, array<int, int[]>>|string  $input
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

        return BlurhashImageFactory::decodeToImage($map);
    }

    /**
     * Upper bound on width × height for decode output and encode input maps.
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

        $prefixWidth  = \count($parts) === 2 ? Internal::parsePositiveInt($parts[0]) : null;
        $prefixHeight = \count($parts) === 2 ? Internal::parsePositiveInt($parts[1]) : null;

        if ($prefixWidth === null || $prefixHeight === null) {
            throw new InvalidArgumentException(
                'Blurhash size prefix must be "<width:height>" with positive integers, e.g. "<32:18>".',
            );
        }

        return [$blurhash, $prefixWidth, $prefixHeight];
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
     * @param array<int, array<int, int[]>>  $map
     *
     * @return array{0: int, 1: int}
     */
    private static function mapDimensions(
        array $map,
        bool $pixelsAreLinear = false,
    ): array {
        self::guardMapShape($map, $pixelsAreLinear);

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
        bool $pixelsAreLinear = false,
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

        BlurhashColor::guardRgbPixel($firstRow[0], 0, 0, requireSrgbRange: ! $pixelsAreLinear);

        for ($col = 1; $col < $width; $col++) {
            BlurhashColor::guardRgbPixel($firstRow[$col], 0, $col, requireSrgbRange: ! $pixelsAreLinear);
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
                BlurhashColor::guardRgbPixel($mapRow[$col], $row, $col, requireSrgbRange: ! $pixelsAreLinear);
            }
        }
    }
}
