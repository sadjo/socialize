<?php

declare(strict_types=1);

namespace App\Core\Application\Post;

use App\Core\Domain\Event\PostArchived;
use App\Core\Domain\Event\PostUnarchived;
use App\Core\Domain\Post\Post;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Archivage d'une publication, comme sur Instagram : elle disparaît du profil et des fils,
 * reste visible par son auteur et retrouve sa visibilité en sortant des archives.
 */
final readonly class PostArchiver
{
    public function __construct(
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    public function archive(Post $post): void
    {
        if ($post->isArchived()) {
            return;
        }
        $post->archive($this->clock->now());
        $this->em->flush();
        $this->events->dispatch(new PostArchived($post));
    }

    public function unarchive(Post $post): void
    {
        if (!$post->isArchived()) {
            return;
        }
        $post->unarchive();
        $this->em->flush();
        $this->events->dispatch(new PostUnarchived($post));
    }
}
