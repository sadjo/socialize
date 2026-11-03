<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Application\Feed\Cursor;
use App\Core\Application\Feed\TimelinePage;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\PublicId;
use App\Core\Domain\Social\Follow;
use App\Core\Domain\Social\FollowState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<Post>
 */
final class PostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    public function findByPublicId(string $publicId): ?Post
    {
        $id = PublicId::decode($publicId);

        return $id instanceof \Symfony\Component\Uid\Uuid ? $this->find($id) : null;
    }

    /**
     * Fil « Abonnements » : ses propres publications et celles des comptes suivis.
     */
    public function homeTimeline(Account $viewer, ?Cursor $cursor, int $limit): TimelinePage
    {
        $followed = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(f.following)')
            ->from(Follow::class, 'f')
            ->where('f.follower = :viewer')
            ->andWhere('f.state = :accepted');

        $qb = $this->base()
            ->where('p.account = :viewer')
            ->orWhere(\sprintf('p.account IN (%s) AND p.visibility IN (:shared)', $followed->getDQL()))
            ->setParameter('viewer', $viewer->getId(), UuidType::NAME)
            ->setParameter('accepted', FollowState::Accepted)
            ->setParameter('shared', [Visibility::Public, Visibility::Unlisted, Visibility::Followers]);

        return $this->page($qb, $cursor, $limit);
    }

    /**
     * Fil « Local » : publications publiques des comptes de l'instance.
     */
    public function localTimeline(?Cursor $cursor, int $limit): TimelinePage
    {
        $qb = $this->base()
            ->where('a.domain IS NULL')
            ->andWhere('p.visibility = :public')
            ->setParameter('public', Visibility::Public);

        return $this->page($qb, $cursor, $limit);
    }

    /**
     * Publications d'un profil, filtrées selon ce que le visiteur a le droit de voir.
     *
     * @param list<Visibility> $visible
     */
    public function profileTimeline(Account $account, array $visible, ?Cursor $cursor, int $limit): TimelinePage
    {
        $qb = $this->base()
            ->where('p.account = :account')
            ->andWhere('p.visibility IN (:visible)')
            ->setParameter('account', $account->getId(), UuidType::NAME)
            ->setParameter('visible', $visible);

        return $this->page($qb, $cursor, $limit);
    }

    /**
     * @param list<Visibility> $visible
     */
    public function countForProfile(Account $account, array $visible): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.account = :account')
            ->andWhere('p.visibility IN (:visible)')
            ->setParameter('account', $account->getId(), UuidType::NAME)
            ->setParameter('visible', $visible)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function base(): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->addSelect('a')
            ->join('p.account', 'a')
            ->orderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC');
    }

    private function page(QueryBuilder $qb, ?Cursor $cursor, int $limit): TimelinePage
    {
        if ($cursor instanceof Cursor) {
            $qb->andWhere('p.publishedAt < :cursorDate OR (p.publishedAt = :cursorDate AND p.id < :cursorId)')
                ->setParameter('cursorDate', $cursor->publishedAt)
                ->setParameter('cursorId', $cursor->id, UuidType::NAME);
        }

        /** @var list<Post> $posts */
        $posts = $qb->setMaxResults($limit + 1)->getQuery()->getResult();

        $next = null;
        if (\count($posts) > $limit) {
            array_pop($posts);
            $last = array_last($posts);
            \assert(null !== $last);
            $next = Cursor::after($last);
        }

        return new TimelinePage($posts, $next);
    }
}
