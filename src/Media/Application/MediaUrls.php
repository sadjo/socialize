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
}
