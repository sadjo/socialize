<?php

declare(strict_types=1);

namespace App\Core\Application\Story;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Story\Story;
use App\Core\Infrastructure\Doctrine\FollowRepository;

/**
 * Qui peut voir une story : le compte lui-même toujours ; les autres tant qu'elle n'a pas expiré
 * (ou si elle est à la une), selon son public (tout le monde ou abonnés).
 */
final readonly class StoryAccess
{
    public function __construct(
        private FollowRepository $follows,
    ) {
    }

    public function canView(Story $story, ?Account $viewer, \DateTimeImmutable $now): bool
    {
        if ($story->isOwnedBy($viewer)) {
            return true;
        }
        if (!$story->isActive($now) && !$story->getHighlight() instanceof \App\Core\Domain\Story\Highlight) {
            return false;
        }

        return \in_array($story->getAudience(), $this->audiencesFor($story->getAccount(), $viewer), true);
    }

    /**
     * @return list<Visibility>
     */
    public function audiencesFor(Account $author, ?Account $viewer): array
    {
        if ($viewer instanceof Account && $viewer->getId()->equals($author->getId())) {
            return [Visibility::Public, Visibility::Followers];
        }

        return $this->follows->isFollowing($viewer, $author)
            ? [Visibility::Public, Visibility::Followers]
            : [Visibility::Public];
    }
}
