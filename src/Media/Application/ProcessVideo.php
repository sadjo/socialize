<?php

declare(strict_types=1);

namespace App\Media\Application;

/**
 * Message Messenger : transcoder la vidéo d'une publication.
 */
final readonly class ProcessVideo
{
    public function __construct(
        public string $mediaId,
    ) {
    }
}
