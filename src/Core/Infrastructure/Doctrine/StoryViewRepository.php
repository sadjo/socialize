<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Story\Story;
use App\Core\Domain\Story\StoryView;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StoryView>
 */
final class StoryViewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoryView::class);
    }

    public function hasSeen(Account $account, Story $story): bool
    {
        return null !== $this->findOneBy(['account' => $account, 'story' => $story]);
    }

    /**
     * Identifiants (base58) des stories de la liste déjà vues par le compte.
     *
     * @param list<Story> $stories
     *
     * @return array<string, true>
     */
    public function seenAmong(?Account $account, array $stories): array
    {
        if (!$account instanceof Account || [] === $stories) {
            return [];
        }

        $seen = [];
        foreach ($this->findBy(['account' => $account, 'story' => $stories]) as $view) {
            $seen[$view->getStory()->getPublicId()] = true;
        }

        return $seen;
    }
}
