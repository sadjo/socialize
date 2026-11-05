<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Post;

/**
 * Un compte local n'aime plus une publication (le like est déjà supprimé).
 */
final readonly class PostUnliked
{
    public function __construct(
        public Account $account,
        public Post $post,
        public ?string $likeApUri,
    ) {
    }
}
