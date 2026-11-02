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
        $path = $media->getDerivative($name);

        return null !== $path ? $this->path($path) : null;
    }
}
