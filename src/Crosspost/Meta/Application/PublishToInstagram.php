<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Application;

/**
 * Étape suivante d'un envoi vers Instagram (voir InstagramPublishing).
 */
final readonly class PublishToInstagram
{
    public function __construct(
        public string $crosspostId,
    ) {
    }
}
