<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

use App\Core\Domain\Post\Post;

/**
 * Une publication locale vient d'être publiée.
 */
final readonly class PostPublished
{
    public function __construct(
        public Post $post,
    ) {
    }
}
