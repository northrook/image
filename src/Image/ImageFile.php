<?php

declare(strict_types=1);

namespace Northrook\Core\Image;

use LogicException;
use SplFileInfo;
use SplFileObject;

/**
 * Readable on-disk image file with EXIF-aware dimensions and MIME detection.
 *
 * Intervention handles pixel rotation on read when using {@see Image::from()}.
 *
 * {@see ImageFile::displayDimensions()} returns the oriented display size for aspect calculations.
 */
final class ImageFile extends SplFileObject
{
    public function __construct(
        string $pathname,
    ) {
        if (! \is_file($pathname)) {
            throw new LogicException(
                \sprintf("File '%s' does not exist.", $pathname),
            );
        }

        if (! \is_readable($pathname)) {
            throw new LogicException(
                \sprintf("File '%s' is not readable.", $pathname),
            );
        }

        parent::__construct($pathname, 'rb');
    }

    public static function open(
        SplFileInfo|string $path,
    ): self {
        if ($path instanceof SplFileInfo) {
            $path = $path->getPathname();
        }

        return new self($path);
    }

    public function mimeType(): string
    {
        $imageMeta = \getimagesize($this->getPathname());

        if (! isset($imageMeta['mime'])) {
            throw new LogicException(
                \sprintf(
                    "Could not determine MIME type for image file '%s'.",
                    $this->getPathname(),
                ),
            );
        }

        return $imageMeta['mime'];
    }

    /**
     * Stored width and height from the image header (before EXIF orientation).
     *
     * @return array{0: int, 1: int}
     */
    public function storedDimensions(): array
    {
        $imageMeta = \getimagesize($this->getPathname())
        ?: throw new LogicException(\sprintf(
            'Could not read image dimensions from "%s".',
            $this->getPathname(),
        ));

        return [(int) $imageMeta[0], (int) $imageMeta[1]];
    }

    /**
     * Display width and height after applying EXIF orientation.
     *
     * @return array{0: int, 1: int}
     */
    public function displayDimensions(): array
    {
        [$width, $height] = $this->storedDimensions();

        return self::orientedDimensions($width, $height, $this->exifOrientation());
    }

    public function exifOrientation(): null|int
    {
        try {
            $exif = @\exif_read_data($this->getPathname(), 'IFD0', true);
        } catch (\Throwable) {
            return null;
        }

        if (! \is_array($exif) || ! isset($exif['IFD0']['Orientation'])) {
            return null;
        }

        return (int) $exif['IFD0']['Orientation'];
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function orientedDimensions(
        int $width,
        int $height,
        null|int $orientation,
    ): array {
        if ($orientation !== null && self::swapsDimensions($orientation)) {
            return [$height, $width];
        }

        return [$width, $height];
    }

    public static function swapsDimensions(
        int $orientation,
    ): bool {
        return \in_array($orientation, [5, 6, 7, 8], true);
    }
}
