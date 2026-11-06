<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Domain\Post\Post;
use App\Core\Domain\Social\Comment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<Comment>
 */
final class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    public function findByPublicId(string $publicId): ?Comment
    {
        $id = \App\Core\Domain\PublicId::decode($publicId);

        return $id instanceof \Symfony\Component\Uid\Uuid ? $this->find($id) : null;
    }

    /**
     * Derniers commentaires d'une publication, du plus ancien au plus récent (aperçu dans le fil).
     *
     * @return list<Comment>
     */
    public function latestFor(Post $post, int $limit): array
    {
        /** @var list<Comment> $comments */
        $comments = $this->createQueryBuilder('c')
            ->addSelect('a')
            ->join('c.account', 'a')
            ->where('c.post = :post')
            ->setParameter('post', $post->getId(), UuidType::NAME)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_reverse($comments);
    }

    /**
     * @return list<Comment>
     */
    public function forPost(Post $post, int $limit = 200): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('a')
            ->join('c.account', 'a')
            ->where('c.post = :post')
            ->setParameter('post', $post->getId(), UuidType::NAME)
            ->orderBy('c.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
