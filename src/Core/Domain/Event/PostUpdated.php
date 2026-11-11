<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;

/**
 * L'auteur a modifié une publication (texte, visibilité ou médias).
 */
final readonly class PostUpdated
{
    /**
     * @param list<Account> $previousAudience membres des groupes avant la modification (visibilité « Groupes »)
     */
    public function __construct(
        public Post $post,
        public Visibility $previousVisibility,
        public array $previousAudience = [],
    ) {
    }
}
