<?php

declare(strict_types=1);

namespace App\Core\Application\Story;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Story\Highlight;
use App\Core\Domain\Story\Story;
use App\Core\Infrastructure\Doctrine\StoryRepository;
use App\Core\Infrastructure\Doctrine\StoryViewRepository;
use Psr\Clock\ClockInterface;

/**
 * Compose la barre de stories : son propre compte d'abord, puis les comptes suivis
 * avec des stories non vues (les plus récentes d'abord), puis ceux déjà vus.
 */
final readonly class StoryFeed
{
    public function __construct(
        private StoryRepository $stories,
        private StoryViewRepository $views,
        private StoryAccess $access,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<StoryGroup>
     */
    public function barFor(Account $viewer): array
    {
        $now = $this->clock->now();
        $byAccount = [];
        foreach ($this->stories->activeForFeed($viewer, $now) as $story) {
            if ($this->access->canView($story, $viewer, $now)) {
                $byAccount[$story->getAccount()->getId()->toRfc4122()][] = $story;
            }
        }

        $seen = $this->views->seenAmong($viewer, array_merge(...array_values($byAccount ?: [[]])));
        $groups = array_map(static fn (array $stories): StoryGroup => new StoryGroup($stories[0]->getAccount(), $stories, $seen), array_values($byAccount));

        usort($groups, static function (StoryGroup $a, StoryGroup $b) use ($viewer): int {
            $own = [$b->account->getId()->equals($viewer->getId()), $a->account->getId()->equals($viewer->getId())];
            if ($own[0] !== $own[1]) {
                return $own[0] <=> $own[1];
            }
            if ($a->isFullySeen() !== $b->isFullySeen()) {
                return $a->isFullySeen() <=> $b->isFullySeen();
            }

            return $b->latest()->getCreatedAt() <=> $a->latest()->getCreatedAt();
        });

        return $groups;
    }

    /**
     * Stories en cours d'un compte visibles par le visiteur.
     */
    public function groupOf(Account $author, ?Account $viewer): ?StoryGroup
    {
        $now = $this->clock->now();
        $stories = array_values(array_filter(
            $this->stories->activeOf($author, $this->access->audiencesFor($author, $viewer), $now),
            fn (Story $s): bool => $this->access->canView($s, $viewer, $now),
        ));
        if ([] === $stories) {
            return null;
        }

        return new StoryGroup($author, $stories, $this->views->seenAmong($viewer, $stories));
    }

    /**
     * Stories d'une story à la une visibles par le visiteur.
     */
    public function highlight(Highlight $highlight, ?Account $viewer): ?StoryGroup
    {
        $now = $this->clock->now();
        $stories = array_values(array_filter(
            $highlight->getStories()->toArray(),
            fn (Story $s): bool => $this->access->canView($s, $viewer, $now) && (bool) $s->getMedia()?->isReady(),
        ));

        return [] === $stories ? null : new StoryGroup($highlight->getAccount(), $stories, $this->views->seenAmong($viewer, $stories));
    }
}
