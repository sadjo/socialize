<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Infrastructure\Doctrine;

use App\Core\Domain\Post\Post;
use App\Core\Domain\Story\Story;
use App\Crosspost\Meta\Domain\InstagramCrosspost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InstagramCrosspost>
 */
final class InstagramCrosspostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstagramCrosspost::class);
    }

    public function forPost(Post $post): ?InstagramCrosspost
    {
        return $this->findOneBy(['post' => $post]);
    }

    public function forStory(Story $story): ?InstagramCrosspost
    {
        return $this->findOneBy(['story' => $story]);
    }
}
