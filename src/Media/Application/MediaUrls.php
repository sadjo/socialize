<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Core\Domain\Media\MediaAttachment;

/**
 * Adresses publiques des copies, servies par Caddy depuis STORAGE_DIR/public.
 */
final class MediaUrls
{
    public const string PREFIX = '/m/';

    public function path(string $relativePath): string
    {
        return self::PREFIX.ltrim($relativePath, '/');
    }

    public function derivative(MediaAttachment $media, string $name): ?string
    {
        // Média distant copié ici : ses copies locales, sauf la vidéo, qui reste sur son instance d'origine.
        if ($media->hasLocalCopies()) {
            $path = MediaAttachment::DERIVATIVE_VIDEO === $name ? null : $media->getDerivative($name);
            if (null !== $path) {
                return $this->path($path);
            }
        }
        if ($media->isRemote()) {
            // Média distant, sans copie locale : l'affiche d'une vidéo n'existe que si l'instance d'origine l'a fournie.
            $poster = $media->getDerivative(MediaAttachment::DERIVATIVE_POSTER);

            return match (true) {
                MediaAttachment::DERIVATIVE_POSTER === $name => $poster,
                $media->isVideo() && MediaAttachment::DERIVATIVE_THUMB === $name => $poster,
                default => $media->getRemoteUrl(),
            };
        }
        $path = $media->getDerivative($name);

        return null !== $path ? $this->path($path) : null;
    }

    /**
     * Liste srcset d'une famille de copies (ImageSizes::LARGE ou ImageSizes::THUMB) : chaque copie présente avec sa
     * largeur. Null pour un média distant sans copie locale ou s'il n'y a qu'une taille (le src suffit).
     */
    public function srcset(MediaAttachment $media, string $family): ?string
    {
        if (($media->isRemote() && !$media->hasLocalCopies()) || ($media->isVideo() && ImageSizes::LARGE === $family)) {
            return null;
        }
        $candidates = [];
        foreach (array_keys(ImageSizes::widths($family)) as $name) {
            $path = $media->getDerivative($name);
            if (null === $path) {
                continue;
            }
            $width = ImageSizes::actualWidth($name, $media->getWidth(), $media->getHeight());
            // Petite image d'origine : plusieurs copies de même largeur, une seule suffit.
            $candidates[$width] ??= $this->path($path).' '.$width.'w';
        }

        return \count($candidates) > 1 ? implode(', ', $candidates) : null;
    }
}
