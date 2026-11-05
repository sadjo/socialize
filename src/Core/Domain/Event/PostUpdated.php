<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;

/**
 * L'auteur a modifié une publication (texte, visibilité ou médias).
 */
final readonly class PostUpdated
{
    public function __construct(
        public Post $post,
        public Visibility $previousVisibility,
    ) {
    }
}
