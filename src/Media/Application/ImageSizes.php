<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Core\Domain\Media\MediaAttachment;

/**
 * Tailles des copies d'une image. Plusieurs largeurs par famille, pour que le navigateur choisisse (srcset) celle
 * qui correspond à l'écran : une image réduite de beaucoup par le navigateur scintille et paraît moins nette.
 *
 * - « large » : image entière, dans un cadre de largeur donnée et de hauteur 16/9 de la largeur ;
 * - « thumb » : vignette 4:5 de la grille, recadrée sur la partie la plus intéressante.
 */
final class ImageSizes
{
    public const string LARGE = 'large';
    public const string THUMB = 'thumb';

    /** @var array<string, int> nom de la copie => largeur, de la plus petite à la plus grande */
    private const array LARGE_WIDTHS = ['large_640' => 640, 'large_960' => 960, MediaAttachment::DERIVATIVE_LARGE => 1440];

    /** @var array<string, int> */
    private const array THUMB_WIDTHS = ['thumb_240' => 240, 'thumb_360' => 360, MediaAttachment::DERIVATIVE_THUMB => 480, 'thumb_720' => 720];

    /**
     * @return array<string, int> nom de la copie => largeur
     */
    public static function widths(string $family): array
    {
        return match ($family) {
            self::LARGE => self::LARGE_WIDTHS,
            self::THUMB => self::THUMB_WIDTHS,
            default => throw new \InvalidArgumentException(\sprintf('Famille de copies inconnue : %s.', $family)),
        };
    }

    /**
     * @return list<string> toutes les copies d'une image
     */
    public static function names(): array
    {
        return [...array_keys(self::LARGE_WIDTHS), ...array_keys(self::THUMB_WIDTHS)];
    }

    /**
     * @return list<string> copies tirées de l'affiche d'une vidéo (vignettes de la grille)
     */
    public static function videoNames(): array
    {
        return array_keys(self::THUMB_WIDTHS);
    }

    /**
     * Dimensions de la copie : [largeur, hauteur, recadrée].
     *
     * @return array{int, int, bool}
     */
    public static function box(string $name): array
    {
        if (isset(self::LARGE_WIDTHS[$name])) {
            $width = self::LARGE_WIDTHS[$name];

            return [$width, intdiv($width * 16, 9), false];
        }
        if (isset(self::THUMB_WIDTHS[$name])) {
            $width = self::THUMB_WIDTHS[$name];

            return [$width, intdiv($width * 5, 4), true];
        }
        throw new \InvalidArgumentException(\sprintf('Copie inconnue : %s.', $name));
    }

    /**
     * Largeur réelle d'une copie pour une image d'origine donnée : une image entière n'est jamais agrandie.
     */
    public static function actualWidth(string $name, ?int $originalWidth, ?int $originalHeight): int
    {
        [$width, $height, $crop] = self::box($name);
        if ($crop || null === $originalWidth || null === $originalHeight || $originalWidth < 1 || $originalHeight < 1) {
            return $width;
        }
        $scale = min(1, $width / $originalWidth, $height / $originalHeight);

        return max(1, (int) round($originalWidth * $scale));
    }
}
