<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

use App\Core\Domain\Post\Post;

/**
 * Une publication va être supprimée (envoyé avant la suppression).
 */
final readonly class PostDeleting
{
    public function __construct(
        public Post $post,
    ) {
    }
}
