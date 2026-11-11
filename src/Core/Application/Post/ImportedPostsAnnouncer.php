<?php

declare(strict_types=1);

namespace App\Core\Application\Post;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Event\PostPublished;
use App\Core\Domain\Post\Post;
use App\Core\Infrastructure\Doctrine\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Annonce aux abonnés du fédivers des publications importées discrètement (import « public discret ») :
 * chacune leur est envoyée comme une nouvelle publication, avec sa date d'origine.
 */
final readonly class ImportedPostsAnnouncer
{
    public function __construct(
        private PostRepository $posts,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * @return list<Post> publications à annoncer, de la plus ancienne à la plus récente
     */
    public function pending(Account $account): array
    {
        return $this->posts->importedNotAnnounced($account);
    }

    /**
     * Annonce une publication. Une publication dont une vidéo est encore en conversion sera annoncée
     * à la fin de la conversion (ProcessVideoHandler).
     *
     * @return bool true si elle part tout de suite, false si elle attend sa vidéo
     */
    public function announce(Post $post): bool
    {
        $post->allowAnnouncement();
        $this->em->flush();
        if ($post->isProcessing()) {
            return false;
        }
        $this->events->dispatch(new PostPublished($post));

        return true;
    }
}
