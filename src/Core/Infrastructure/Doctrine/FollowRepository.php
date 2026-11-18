<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Application\Social\FollowPage;
use App\Core\Domain\Account\Account;
use App\Core\Domain\PublicId;
use App\Core\Domain\Social\Follow;
use App\Core\Domain\Social\FollowState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

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

    /**
     * Boîtes de réception des abonnés distants (boîte partagée de leur instance si possible), sans doublon.
     *
     * @return list<string>
     */
    public function remoteFollowerInboxes(Account $account): array
    {
        $inboxes = [];
        foreach ($this->findBy(['following' => $account, 'state' => FollowState::Accepted]) as $follow) {
            $follower = $follow->getFollower();
            if (!$follower->isLocal() && null !== $follower->getDeliveryInbox()) {
                $inboxes[$follower->getDeliveryInbox()] = true;
            }
        }

        return array_keys($inboxes);
    }

    public function findByApUri(string $apUri): ?Follow
    {
        return $this->findOneBy(['apUri' => $apUri]);
    }

    /**
     * Un compte local suit-il ce compte distant ? (pour accepter ses publications).
     */
    public function hasLocalFollower(Account $account): bool
    {
        return array_any($this->findBy(['following' => $account, 'state' => FollowState::Accepted]), static fn (Follow $follow): bool => $follow->getFollower()->isLocal());
    }

    public function countFollowers(Account $account): int
    {
        return $this->count(['following' => $account, 'state' => FollowState::Accepted]);
    }

    /**
     * Demandes d'abonnement à valider par un compte, de la plus ancienne à la plus récente.
     *
     * @return list<Follow>
     */
    public function pendingRequestsOf(Account $account, int $limit = 500): array
    {
        /* @var list<Follow> */
        return $this->createQueryBuilder('f')
            ->addSelect('a')
            ->join('f.follower', 'a')
            ->where('f.following = :account')
            ->andWhere('f.state = :pending')
            ->setParameter('account', $account->getId(), UuidType::NAME)
            ->setParameter('pending', FollowState::Pending)
            ->orderBy('f.id', \SortDirection::Ascending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countPendingRequests(Account $account): int
    {
        return $this->count(['following' => $account, 'state' => FollowState::Pending]);
    }

    public function countFollowing(Account $account): int
    {
        return $this->count(['follower' => $account, 'state' => FollowState::Accepted]);
    }

    /**
     * Abonnés acceptés d'un compte, du plus récent au plus ancien.
     */
    /**
     * Tous les abonnés (abonnements acceptés), pour les choisir dans un groupe.
     *
     * @return list<Account>
     */
    public function followersOf(Account $account, int $limit = 5000): array
    {
        /** @var list<Follow> $follows */
        $follows = $this->findBy(['following' => $account, 'state' => FollowState::Accepted], ['createdAt' => 'DESC'], $limit);

        return array_map(static fn (Follow $f): Account => $f->getFollower(), $follows);
    }

    public function followersPage(Account $account, ?string $after, int $limit): FollowPage
    {
        return $this->page('following', 'follower', $account, $after, $limit);
    }

    /**
     * Comptes suivis (abonnements acceptés), du plus récent au plus ancien.
     */
    public function followingPage(Account $account, ?string $after, int $limit): FollowPage
    {
        return $this->page('follower', 'following', $account, $after, $limit);
    }

    /**
     * Les identifiants UUID v7 suivent l'ordre de création : ils servent de curseur.
     *
     * @param 'follower'|'following' $side
     * @param 'follower'|'following' $other
     */
    private function page(string $side, string $other, Account $account, ?string $after, int $limit): FollowPage
    {
        $qb = $this->createQueryBuilder('f')
            ->addSelect('a')
            ->join('f.'.$other, 'a')
            ->where('f.'.$side.' = :account')
            ->andWhere('f.state = :state')
            ->setParameter('account', $account->getId(), UuidType::NAME)
            ->setParameter('state', FollowState::Accepted)
            ->orderBy('f.id', \SortDirection::Descending)
            ->setMaxResults($limit + 1);

        $cursor = null !== $after ? PublicId::decode($after) : null;
        if ($cursor instanceof Uuid) {
            $qb->andWhere('f.id < :cursor')->setParameter('cursor', $cursor, UuidType::NAME);
        }

        /** @var list<Follow> $follows */
        $follows = $qb->getQuery()->getResult();
        $next = null;
        if (\count($follows) > $limit) {
            array_pop($follows);
            $next = $follows[\count($follows) - 1]->getId()->toBase58();
        }

        return new FollowPage(
            array_map(static fn (Follow $follow): Account => 'follower' === $other ? $follow->getFollower() : $follow->getFollowing(), $follows),
            $next,
        );
    }
}
