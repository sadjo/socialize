<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

use App\Core\Domain\Social\Like;

/**
 * Un compte local vient d'aimer une publication.
 */
final readonly class PostLiked
{
    public function __construct(
        public Like $like,
    ) {
    }
}
