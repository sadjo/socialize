<?php

declare(strict_types=1);

namespace App\Federation\Application;

/**
 * Message Messenger : fabriquer ici les copies d'un média reçu du fédivers (image, ou affiche d'une vidéo).
 */
final readonly class CacheRemoteMedia
{
    public function __construct(
        public string $mediaId,
    ) {
    }
}
