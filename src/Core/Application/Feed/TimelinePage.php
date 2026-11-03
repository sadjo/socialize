<?php

declare(strict_types=1);

namespace App\Core\Application\Feed;

use App\Core\Domain\Post\Post;

final readonly class TimelinePage
{
    /**
     * @param list<Post> $posts
     */
    public function __construct(
        public array $posts,
        public ?Cursor $next,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->posts;
    }
}
