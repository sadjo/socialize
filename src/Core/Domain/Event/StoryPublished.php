<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

use App\Core\Domain\Story\Story;

/**
 * Une story locale vient d'être publiée, ses médias prêts (vidéo convertie).
 */
final readonly class StoryPublished
{
    public function __construct(
        public Story $story,
    ) {
    }
}
