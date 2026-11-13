<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Social\Like;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Like>
 */
final class LikeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Like::class);
    }

    public function findOneFor(Account $account, Post $post): ?Like
    {
        return $this->findOneBy(['account' => $account, 'post' => $post]);
    }

    /**
     * Comptes (locaux ou du fédivers) qui ont aimé la publication, du plus récent au plus ancien.
     *
     * @return list<Account>
     */
    public function likersOf(Post $post, int $limit = 200): array
    {
        // findBy convertit l'identifiant avec le bon type (UUID binaire ou natif selon la base).
        $likes = $this->findBy(['post' => $post], ['createdAt' => 'DESC'], $limit);

        return array_map(static fn (Like $like): Account => $like->getAccount(), $likes);
    }

    /**
     * Identifiants (base58) des publications de la liste que le compte a aimées.
     *
     * @param iterable<Post> $posts
     *
     * @return array<string, true>
     */
    public function likedAmong(?Account $account, iterable $posts): array
    {
        $posts = [...$posts];
        if (!$account instanceof Account || [] === $posts) {
            return [];
        }

        // findBy convertit les identifiants avec le bon type (UUID binaire ou natif selon la base).
        $likes = $this->findBy(['account' => $account, 'post' => $posts]);

        $liked = [];
        foreach ($likes as $like) {
            $liked[$like->getPost()->getPublicId()] = true;
        }

        return $liked;
    }
}
