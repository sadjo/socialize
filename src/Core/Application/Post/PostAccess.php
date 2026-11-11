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
        if ($post->isArchived() || $post->isOwnedBy($viewer)) {
            return $post->isOwnedBy($viewer);
        }
        if (!\in_array($post->getVisibility(), $this->visibleFor($post->getAccount(), $viewer), true)) {
            return false;
        }

        // Réservée à des groupes : il faut en plus être membre d'un d'eux (l'abonnement est déjà vérifié).
        return Visibility::Groups !== $post->getVisibility() || $post->isInAudienceGroups($viewer);
    }

    /**
     * Destinataires d'une publication réservée à des groupes : membres de ses groupes encore abonnés.
     *
     * @return list<Account>
     */
    public function groupAudience(Post $post): array
    {
        if (Visibility::Groups !== $post->getVisibility()) {
            return [];
        }

        return array_values(array_filter($post->getAudienceMembers(), fn (Account $m): bool => $this->follows->isFollowing($m, $post->getAccount())));
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
        // « Groupes » : réservé en plus aux membres des groupes de chaque publication (voir canView et PostRepository).
        if ($this->follows->isFollowing($viewer, $author)) {
            return [Visibility::Public, Visibility::Unlisted, Visibility::Followers, Visibility::Groups];
        }

        return [Visibility::Public, Visibility::Unlisted];
    }
}
