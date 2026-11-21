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

    /**
     * Met plusieurs stories à la une d'un coup (dans l'ordre donné), dans la même story à la une.
     *
     * @param list<Story> $stories
     */
    public function pinAll(array $stories, ?Highlight $highlight, ?string $newTitle = null): ?Highlight
    {
        foreach ($stories as $story) {
            $highlight = $this->pin($story, $highlight, $newTitle);
        }

        return $highlight;
    }

    public function rename(Highlight $highlight, string $title): void
    {
        $highlight->rename($title);
        $this->em->flush();
    }

    public function unpin(Story $story): void
    {
        $highlight = $story->getHighlight();
        // Collection retirée d'abord : la charger après pinTo(null) relirait la story depuis la base et lui rendrait sa
        // story à la une (le retrait n'était alors jamais enregistré, sauf pour la dernière story).
        $highlight?->getStories()->removeElement($story);
        $story->pinTo(null);
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
