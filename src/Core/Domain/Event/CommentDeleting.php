<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

use App\Core\Domain\Social\Comment;

/**
 * Un commentaire va être supprimé (il est encore lisible pour prévenir le fédivers).
 */
final readonly class CommentDeleting
{
    public function __construct(
        public Comment $comment,
    ) {
    }
}
