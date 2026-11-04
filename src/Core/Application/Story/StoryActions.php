<?php

declare(strict_types=1);

namespace App\Core\Application\Story;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Story\Highlight;
use App\Core\Domain\Story\Story;
use App\Core\Domain\Story\StoryView;
use App\Core\Infrastructure\Doctrine\HighlightRepository;
use App\Core\Infrastructure\Doctrine\StoryViewRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class StoryActions
{
    public function __construct(
        private EntityManagerInterface $em,
        private StoryViewRepository $views,
        private HighlightRepository $highlights,
    ) {
    }

    /**
     * Note qu'un compte a vu une story. Les vues de son propre compte ne comptent pas.
     */
    public function markSeen(Account $viewer, Story $story): void
    {
        if ($story->isOwnedBy($viewer) || $this->views->hasSeen($viewer, $story)) {
            return;
        }

        $this->em->persist(new StoryView($story, $viewer));
        $story->incrementViews();
        $this->em->flush();
    }

    /**
     * Met une story à la une : dans une story à la une existante, ou dans une nouvelle si un titre est donné.
     */
    public function pin(Story $story, ?Highlight $highlight, ?string $newTitle = null): Highlight
    {
        $account = $story->getAccount();
        if (!$highlight instanceof Highlight) {
            $highlight = new Highlight($account, $newTitle ?? '', $this->highlights->nextPosition($account));
            $this->em->persist($highlight);
        } elseif (!$highlight->getAccount()->getId()->equals($account->getId())) {
            throw new \DomainException('Cette story à la une appartient à un autre compte.');
        }

        $story->pinTo($highlight);
        $highlight->getStories()->add($story);
        $this->em->flush();

        return $highlight;
    }

    public function unpin(Story $story): void
    {
        $highlight = $story->getHighlight();
        $story->pinTo(null);
        $highlight?->getStories()->removeElement($story);
        if ($highlight instanceof Highlight && $highlight->getStories()->isEmpty()) {
            $this->em->remove($highlight);
        }
        $this->em->flush();
    }

    public function deleteHighlight(Highlight $highlight): void
    {
        foreach ($highlight->getStories() as $story) {
            $story->pinTo(null);
        }
        $this->em->remove($highlight);
        $this->em->flush();
    }
}
