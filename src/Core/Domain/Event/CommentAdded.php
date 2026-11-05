<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

use App\Core\Domain\Social\Comment;

/**
 * Un compte local vient de commenter une publication.
 */
final readonly class CommentAdded
{
    public function __construct(
        public Comment $comment,
    ) {
    }
}
