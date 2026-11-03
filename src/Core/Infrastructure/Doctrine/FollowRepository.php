<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Social\Follow;
use App\Core\Domain\Social\FollowState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Follow>
 */
final class FollowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Follow::class);
    }

    public function findBetween(Account $follower, Account $following): ?Follow
    {
        return $this->findOneBy(['follower' => $follower, 'following' => $following]);
    }

    public function isFollowing(?Account $follower, Account $following): bool
    {
        if (!$follower instanceof Account) {
            return false;
        }

        return null !== $this->findOneBy(['follower' => $follower, 'following' => $following, 'state' => FollowState::Accepted]);
    }

    public function countFollowers(Account $account): int
    {
        return $this->count(['following' => $account, 'state' => FollowState::Accepted]);
    }

    public function countFollowing(Account $account): int
    {
        return $this->count(['follower' => $account, 'state' => FollowState::Accepted]);
    }
}
