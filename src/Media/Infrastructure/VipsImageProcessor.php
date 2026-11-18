<?php

declare(strict_types=1);

namespace App\Media\Infrastructure;

use App\Media\Application\ImageCrop;
use App\Media\Application\ImageSizes;
use App\Media\Application\MediaProcessingException;
use App\Media\Application\ProcessedImage;
use Jcupitt\Vips\Exception as VipsException;
use Jcupitt\Vips\Image;
use kornrunner\Blurhash\Blurhash;

/**
 * Fabrique les copies publiques d'une image avec libvips : image entière et vignettes de grille en plusieurs
 * largeurs (ImageSizes), empreinte floue (blurhash). Les copies perdent toutes leurs métadonnées, dont la position
 * GPS ; l'EXIF de l'original est conservé à part.
 */
final class VipsImageProcessor
{
    private const int WEBP_QUALITY = 82;

    public function process(string $bytes): ProcessedImage
    {
        try {
            $original = Image::newFromBuffer($bytes)->autorot();
            $exif = $this->exif(Image::newFromBuffer($bytes));

            return new ProcessedImage(
                width: $original->width,
                height: $original->height,
                files: $this->derive($bytes, ImageSizes::names()),
                exif: $exif,
                blurhash: $this->blurhash($bytes),
            );
        } catch (VipsException $e) {
            throw new MediaProcessingException('Cette image n’a pas pu être lue. Vérifiez qu’il s’agit bien d’une photo valide.', $e->getCode(), previous: $e);
        }
    }

    /**
     * Copies demandées d'une image (noms d'ImageSizes), en WebP.
     *
     * @param list<string> $names
     *
     * @return array<string, string> contenu WebP par nom de copie
     */
    public function derive(string $bytes, array $names): array
    {
        try {
            $files = [];
            foreach ($names as $name) {
                [$width, $height, $crop] = ImageSizes::box($name);
                $options = $crop ? ['height' => $height, 'crop' => 'attention'] : ['height' => $height, 'size' => 'down'];
                $files[$name] = $this->webp(Image::thumbnail_buffer($bytes, $width, $options));
            }

            return $files;
        } catch (VipsException $e) {
            throw new MediaProcessingException('Cette image n’a pas pu être lue. Vérifiez qu’il s’agit bien d’une photo valide.', $e->getCode(), previous: $e);
        }
    }

    /**
     * Copie source d'une image du profil, redressée et limitée en taille, gardée pour la recadrer plus tard.
     */
    public function profileSource(string $bytes, int $max = 2048): string
    {
        try {
            return $this->webp(Image::thumbnail_buffer($bytes, $max, ['height' => $max, 'size' => 'down']));
        } catch (VipsException $e) {
            throw new MediaProcessingException('Cette image n’a pas pu être lue.', $e->getCode(), previous: $e);
        }
    }

    /**
     * Image du profil aux dimensions voulues : le cadre choisi, ou à défaut la partie la plus intéressante.
     */
    public function framed(string $bytes, int $width, int $height, ?ImageCrop $crop = null): string
    {
        try {
            if (!$crop instanceof ImageCrop) {
                return $this->webp(Image::thumbnail_buffer($bytes, $width, ['height' => $height, 'crop' => 'attention']));
            }
            $image = Image::newFromBuffer($bytes)->autorot();
            $left = min($image->width - 1, (int) round($crop->x * $image->width));
            $top = min($image->height - 1, (int) round($crop->y * $image->height));
            $area = $image->extract_area(
                $left,
                $top,
                max(1, min($image->width - $left, (int) round($crop->width * $image->width))),
                max(1, min($image->height - $top, (int) round($crop->height * $image->height))),
            );

            return $this->webp($area->thumbnail_image($width, ['height' => $height, 'crop' => 'centre', 'size' => 'both']));
        } catch (VipsException $e) {
            throw new MediaProcessingException('Cette image n’a pas pu être lue.', $e->getCode(), previous: $e);
        }
    }

    /**
     * Image insérée dans un texte (bloc Instance) : 1200 pixels de large au plus, redressée, sans métadonnées.
     */
    public function contentImage(string $bytes, int $maxWidth = 1200): string
    {
        try {
            return $this->webp(Image::thumbnail_buffer($bytes, $maxWidth, ['height' => 10 * $maxWidth, 'size' => 'down']));
        } catch (VipsException $e) {
            throw new MediaProcessingException('Cette image n’a pas pu être lue.', $e->getCode(), previous: $e);
        }
    }

    /**
     * Copie JPEG d'une image (les applications comme Instagram ne reçoivent pas toutes le WebP).
     */
    public function jpeg(string $bytes): string
    {
        try {
            $image = Image::newFromBuffer($bytes);
            if ($image->hasAlpha()) {
                $image = $image->flatten(['background' => [255, 255, 255]]);
            }

            return $image->colourspace('srgb')->writeToBuffer('.jpg', ['Q' => 90, 'keep' => 'none']);
        } catch (VipsException $e) {
            throw new MediaProcessingException('Cette image n’a pas pu être convertie.', $e->getCode(), previous: $e);
        }
    }

    private function webp(Image $image): string
    {
        return $image->colourspace('srgb')->writeToBuffer('.webp', ['Q' => self::WEBP_QUALITY, 'keep' => 'none']);
    }

    /**
     * @return array<string, string>
     */
    private function exif(Image $image): array
    {
        $exif = [];
        foreach ($image->getFields() as $field) {
            if (!\is_string($field) || !str_starts_with($field, 'exif-ifd')) {
                continue;
            }
            $value = $image->get($field);
            if (!\is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                continue;
            }
            // libvips ajoute une description technique : « Fujifilm (Fujifilm, ASCII, 9 components, 9 bytes) ».
            if (preg_match('/^(.*) \(.*, \d+ components?, \d+ bytes?\)$/s', $value, $m)) {
                $value = $m[1];
            }
            if ('' !== trim($value)) {
                $exif[substr($field, 5)] = trim($value);
            }
        }
        ksort($exif);

        return $exif;
    }

    private function blurhash(string $bytes): ?string
    {
        $small = Image::thumbnail_buffer($bytes, 32, ['height' => 32])->colourspace('srgb');
        if ($small->hasAlpha()) {
            $small = $small->flatten(['background' => [255, 255, 255]]);
        }
        $small = $small->extract_band(0, ['n' => 3])->cast('uchar');

        $flat = $small->writeToArray();
        $width = $small->width;
        $pixels = [];
        foreach (array_chunk($flat, 3) as $i => $rgb) {
            $pixels[intdiv($i, $width)][$i % $width] = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rgb);
        }

        try {
            return Blurhash::encode($pixels, 4, 3);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
