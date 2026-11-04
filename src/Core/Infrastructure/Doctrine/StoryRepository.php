<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\PublicId;
use App\Core\Domain\Social\Follow;
use App\Core\Domain\Social\FollowState;
use App\Core\Domain\Story\Story;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<Story>
 */
final class StoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Story::class);
    }

    public function findByPublicId(string $publicId): ?Story
    {
        $id = PublicId::decode($publicId);

        return $id instanceof \Symfony\Component\Uid\Uuid ? $this->find($id) : null;
    }

    /**
     * Stories en cours du compte et des comptes qu'il suit, de la plus ancienne à la plus récente.
     *
     * @return list<Story>
     */
    public function activeForFeed(Account $viewer, \DateTimeImmutable $now): array
    {
        $followed = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(f.following)')
            ->from(Follow::class, 'f')
            ->where('f.follower = :viewer')
            ->andWhere('f.state = :accepted');

        /* @var list<Story> */
        return $this->active($now)
            ->andWhere(\sprintf('s.account = :viewer OR s.account IN (%s)', $followed->getDQL()))
            ->setParameter('viewer', $viewer->getId(), UuidType::NAME)
            ->setParameter('accepted', FollowState::Accepted)
            ->getQuery()
            ->getResult();
    }

    /**
     * Stories en cours d'un compte, limitées aux publics que le visiteur peut voir.
     *
     * @param list<Visibility> $audiences
     *
     * @return list<Story>
     */
    public function activeOf(Account $account, array $audiences, \DateTimeImmutable $now): array
    {
        /* @var list<Story> */
        return $this->active($now)
            ->andWhere('s.account = :account')
            ->andWhere('s.audience IN (:audiences)')
            ->setParameter('account', $account->getId(), UuidType::NAME)
            ->setParameter('audiences', $audiences)
            ->getQuery()
            ->getResult();
    }

    /**
     * Stories expirées d'un compte (ses archives), les plus récentes d'abord.
     *
     * @return list<Story>
     */
    public function archiveOf(Account $account, \DateTimeImmutable $now, int $limit = 200): array
    {
        /* @var list<Story> */
        return $this->createQueryBuilder('s')
            ->addSelect('m')
            ->leftJoin('s.media', 'm')
            ->where('s.account = :account')
            ->andWhere('s.expiresAt <= :now')
            ->setParameter('account', $account->getId(), UuidType::NAME)
            ->setParameter('now', $now)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function hasActive(Account $account, \DateTimeImmutable $now): bool
    {
        return null !== $this->active($now)
            ->select('s.id')
            ->andWhere('s.account = :account')
            ->setParameter('account', $account->getId(), UuidType::NAME)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function active(\DateTimeImmutable $now): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->addSelect('a', 'm')
            ->join('s.account', 'a')
            ->leftJoin('s.media', 'm')
            ->where('s.expiresAt > :now')
            ->setParameter('now', $now)
            ->orderBy('s.createdAt', 'ASC');
    }
}
