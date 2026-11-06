<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

use App\Core\Domain\Post\Post;

/**
 * Une publication vient de sortir des archives (elle revient dans le fédivers si sa visibilité le permet).
 */
final readonly class PostUnarchived
{
    public function __construct(
        public Post $post,
    ) {
    }
}
