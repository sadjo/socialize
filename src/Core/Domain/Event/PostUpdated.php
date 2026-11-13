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
     * @param bool          $contentChanged   faux quand seuls les membres de ses groupes ont changé
     * @param bool          $dateChanged      date de publication changée : le fédivers ne la suit pas dans une mise à
     *                                        jour, la publication y est retirée puis renvoyée
     */
    public function __construct(
        public Post $post,
        public Visibility $previousVisibility,
        public array $previousAudience = [],
        public bool $contentChanged = true,
        public bool $dateChanged = false,
    ) {
    }
}
