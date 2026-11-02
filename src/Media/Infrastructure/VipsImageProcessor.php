<?php

declare(strict_types=1);

namespace App\Media\Infrastructure;

use App\Core\Domain\Media\MediaAttachment;
use App\Media\Application\MediaProcessingException;
use App\Media\Application\ProcessedImage;
use Jcupitt\Vips\Exception as VipsException;
use Jcupitt\Vips\Image;
use kornrunner\Blurhash\Blurhash;

/**
 * Fabrique les copies publiques d'une image avec libvips : grande taille, miniature
 * de grille, empreinte floue (blurhash). Les copies perdent toutes leurs métadonnées,
 * dont la position GPS ; l'EXIF de l'original est conservé à part.
 */
final class VipsImageProcessor
{
    private const int LARGE_WIDTH = 1440;
    private const int LARGE_HEIGHT = 2560;
    private const int THUMB_WIDTH = 480;
    private const int THUMB_HEIGHT = 600;
    private const int WEBP_QUALITY = 82;

    public function process(string $bytes): ProcessedImage
    {
        try {
            $original = Image::newFromBuffer($bytes)->autorot();
            $exif = $this->exif(Image::newFromBuffer($bytes));

            $large = Image::thumbnail_buffer($bytes, self::LARGE_WIDTH, ['height' => self::LARGE_HEIGHT, 'size' => 'down']);
            $thumb = Image::thumbnail_buffer($bytes, self::THUMB_WIDTH, ['height' => self::THUMB_HEIGHT, 'crop' => 'attention']);

            return new ProcessedImage(
                width: $original->width,
                height: $original->height,
                files: [
                    MediaAttachment::DERIVATIVE_LARGE => $this->webp($large),
                    MediaAttachment::DERIVATIVE_THUMB => $this->webp($thumb),
                ],
                exif: $exif,
                blurhash: $this->blurhash($bytes),
            );
        } catch (VipsException $e) {
            throw new MediaProcessingException('Cette image n’a pas pu être lue. Vérifiez qu’il s’agit bien d’une photo valide.', $e->getCode(), previous: $e);
        }
    }

    /**
     * Photo de profil carrée.
     */
    public function avatar(string $bytes, int $size = 400): string
    {
        try {
            return $this->webp(Image::thumbnail_buffer($bytes, $size, ['height' => $size, 'crop' => 'attention']));
        } catch (VipsException $e) {
            throw new MediaProcessingException('Cette image n’a pas pu être lue.', $e->getCode(), previous: $e);
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
