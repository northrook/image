<?php

declare(strict_types=1);

namespace Northrook\Core\Image;

use Intervention\Image\Interfaces\ImageInterface;
use InvalidArgumentException;
use LogicException;

/**
 * https://css-tricks.com/almanac/properties/a/aspect-ratio/
 */
final readonly class Aspect
{
    public int $divisor;

    public int $width;

    public int $height;

    public Orientation $orientation;

    public function __construct(ImageFile|ImageInterface|string $source)
    {
        if ($source instanceof ImageInterface) {
            [$width, $height] = self::dimensionsFromImage($source);
        } else {
            $file = $source instanceof ImageFile ? $source : ImageFile::open($source);

            [$width, $height] = $file->displayDimensions();
        }

        Orientation::from($width, $height);

        $divisor = (int) Calc::gcd($width, $height);

        $this->divisor     = $divisor;
        $this->width       = \intdiv($width, $divisor);
        $this->height      = \intdiv($height, $divisor);
        $this->orientation = Orientation::from($this->width, $this->height);
    }

    public static function from(
        ImageFile|ImageInterface|string $source,
    ): self {
        return new self($source);
    }

    public function getRatio(
        string $separator = '/',
    ): string {
        return $this->width . $separator . $this->height;
    }

    public function getFloat(
        null|Orientation $orientation = null,
    ): float {
        $num = match ($orientation ?? $this->orientation) {
            Orientation::PORTRAIT => $this->height / $this->width,
            default               => $this->width / $this->height,
        };

        return (float) \round($num, 4);
    }

    /**
     * @param int  $edge
     *
     * @return array{
     *     0: positive-int,
     *     1: positive-int,
     * }
     */
    public function scaleShortest(
        int $edge,
    ): array {
        $this->guardClampedEdge($edge, 'Shortest edge');

        $longEdge = (int) \round($edge * $this->getFloat());

        return $this->guardPositiveDimensions(
            $this->orientation === Orientation::LANDSCAPE
                ? [$longEdge, $edge]
                : [$edge, $longEdge],
        );
    }

    /**
     * @param int  $edge
     *
     * @return array{
     *     0: positive-int,
     *     1: positive-int,
     * }
     */
    public function scaleLongest(
        int $edge,
    ): array {
        $this->guardClampedEdge($edge, 'Longest edge');

        $shortest = (int) \round($edge / $this->getFloat());

        return $this->guardPositiveDimensions(
            $this->orientation === Orientation::LANDSCAPE
                ? [$edge, $shortest]
                : [$shortest, $edge],
        );
    }

    /**
     * @param int  $width
     *
     * @return array{
     *     0: positive-int,
     *     1: positive-int,
     * }
     */
    public function scaleWidth(
        int $width,
    ): array {
        $this->guardMinEdge($width, 'Width');

        if ($this->orientation === Orientation::SQUARE) {
            $height = $width;
        } else {
            $height = (int) \round($width * $this->getFloat(Orientation::PORTRAIT));
        }

        return $this->guardPositiveDimensions([$width, $height]);
    }

    /**
     * @param int  $height
     *
     * @return array{
     *     0: positive-int,
     *     1: positive-int,
     * }
     */
    public function scaleHeight(
        int $height,
    ): array {
        $this->guardMinEdge($height, 'Height');

        if ($this->orientation === Orientation::SQUARE) {
            $width = $height;
        } else {
            $width = (int) \round($height * $this->getFloat(Orientation::LANDSCAPE));
        }

        return $this->guardPositiveDimensions([$width, $height]);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function dimensionsFromImage(
        ImageInterface $source,
    ): array {
        $width  = $source->width();
        $height = $source->height();

        Orientation::from($width, $height);

        return [$width, $height];
    }

    private function guardClampedEdge(
        int $edge,
        string $label,
    ): void {
        $this->guardMinEdge($edge, $label);

        if ($edge > PixelMapLimits::clamp()) {
            throw new InvalidArgumentException(\sprintf(
                '%s must be at most %d; got %d.',
                $label,
                PixelMapLimits::clamp(),
                $edge,
            ));
        }
    }

    private function guardMinEdge(
        int $edge,
        string $label,
    ): void {
        if ($edge < PixelMapLimits::MIN) {
            throw new InvalidArgumentException(\sprintf(
                '%s must be at least %d; got %d.',
                $label,
                PixelMapLimits::MIN,
                $edge,
            ));
        }
    }

    /**
     * @param array{0: int, 1: int}  $dimensions
     *
     * @return array{
     *     0: positive-int,
     *     1: positive-int,
     * }
     */
    private function guardPositiveDimensions(
        array $dimensions,
    ): array {
        [$width, $height] = $dimensions;

        if ($width < 1 || $height < 1) {
            throw new LogicException(\sprintf(
                'Scaled dimensions must be positive; got %d×%d.',
                $width,
                $height,
            ));
        }

        return [$width, $height];
    }
}
