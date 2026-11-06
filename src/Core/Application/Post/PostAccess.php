<?php

declare(strict_types=1);

namespace App\Core\Application\Post;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Infrastructure\Doctrine\FollowRepository;

/**
 * Règles de visibilité des publications, communes au web, à l'API et à la fédération.
 */
final readonly class PostAccess
{
    public function __construct(
        private FollowRepository $follows,
    ) {
    }

    public function canView(Post $post, ?Account $viewer): bool
    {
        if ($post->isArchived()) {
            return $post->isOwnedBy($viewer);
        }

        return \in_array($post->getVisibility(), $this->visibleFor($post->getAccount(), $viewer), true);
    }

    /**
     * Niveaux de visibilité qu'un visiteur peut voir sur le profil d'un compte
     * (les publications archivées en sont toujours exclues).
     *
     * @return list<Visibility>
     */
    public function visibleFor(Account $author, ?Account $viewer): array
    {
        if ($viewer instanceof Account && $viewer->getId()->equals($author->getId())) {
            return Visibility::cases();
        }
        if ($this->follows->isFollowing($viewer, $author)) {
            return [Visibility::Public, Visibility::Unlisted, Visibility::Followers];
        }

        return [Visibility::Public, Visibility::Unlisted];
    }
}
