<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

use App\Core\Domain\Post\Post;

/**
 * Une publication vient d'être archivée (elle quitte le fédivers si elle y était).
 */
final readonly class PostArchived
{
    public function __construct(
        public Post $post,
    ) {
    }
}
