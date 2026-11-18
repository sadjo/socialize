<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Domain\Post\Post;
use App\Core\Domain\Social\Comment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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
     * Aperçu de plusieurs publications en une requête : pour chacune, ses $limit derniers commentaires, du plus ancien
     * au plus récent (comme latestFor()). Clé : identifiant public de la publication ; une publication sans
     * commentaire n'a pas d'entrée. Un commentaire est gardé s'il en a moins de $limit plus récents sur la même
     * publication (index post_id, created_at).
     *
     * @param iterable<Post> $posts
     *
     * @return array<string, list<Comment>>
     */
    public function latestForPosts(iterable $posts, int $limit): array
    {
        $ids = [];
        foreach ($posts as $post) {
            $ids[] = $post->getId();
        }
        if ([] === $ids || $limit < 1) {
            return [];
        }

        /** @var list<Comment> $comments */
        $comments = $this->createQueryBuilder('c')
            ->addSelect('a')
            ->join('c.account', 'a')
            ->where('c.post IN (:posts)')
            ->andWhere('(SELECT COUNT(n.id) FROM '.Comment::class.' n WHERE n.post = c.post AND (n.createdAt > c.createdAt OR (n.createdAt = c.createdAt AND n.id > c.id))) < :limit')
            ->setParameter('posts', UuidList::of($ids, $this->getEntityManager()->getConnection()->getDatabasePlatform()), ArrayParameterType::STRING)
            ->setParameter('limit', $limit)
            ->orderBy('c.createdAt', \SortDirection::Ascending)
            ->addOrderBy('c.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        $byPost = [];
        foreach ($comments as $comment) {
            $byPost[$comment->getPost()->getPublicId()][] = $comment;
        }

        return $byPost;
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
            ->orderBy('c.createdAt', \SortDirection::Descending)
            ->addOrderBy('c.id', \SortDirection::Descending)
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
            ->orderBy('c.createdAt', \SortDirection::Ascending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
