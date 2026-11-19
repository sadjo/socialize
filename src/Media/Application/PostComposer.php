<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Event\PostDeleting;
use App\Core\Domain\Event\PostPublished;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Music\MusicChoice;
use App\Core\Domain\Post\CrosspostTarget;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Social\AudienceGroup;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Crée une publication avec ses photos et vidéos.
 */
final readonly class PostComposer
{
    public function __construct(
        private EntityManagerInterface $em,
        private MediaIngestor $ingestor,
        private MessageBusInterface $bus,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * @param list<array{file: File, alt: ?string}> $uploads
     * @param list<AudienceGroup>                   $audienceGroups groupes d'abonnés (visibilité « Groupes »)
     * @param list<CrosspostTarget>                 $crosspost      réseaux où l'envoyer aussi (Instagram…)
     */
    public function publish(
        Account $author,
        string $caption,
        array $uploads,
        Visibility $visibility = Visibility::Public,
        ?string $contentWarning = null,
        ?string $location = null,
        ?MusicChoice $music = null,
        array $audienceGroups = [],
        array $crosspost = [],
    ): Post {
        // Sans photo ni vidéo : publication texte, qui doit alors avoir un texte ; ni musique ni publication sur
        // Instagram (qui n'accepte que des médias).
        if ([] === $uploads) {
            if ('' === trim($caption)) {
                throw new MediaProcessingException('Écrivez un texte ou ajoutez une photo ou une vidéo.');
            }
            $music = null;
            $crosspost = [];
        }

        $post = new Post($author, $caption, $visibility, $contentWarning, $location);
        $post->chooseMusic($music);
        $post->restrictTo($audienceGroups);
        $post->requestCrosspost($crosspost);
        $stored = [];
        try {
            foreach ($uploads as $position => $upload) {
                $stored[] = $this->ingestor->attach($post, $upload['file'], $upload['alt'], $position);
            }
        } catch (MediaProcessingException $e) {
            foreach ($stored as $media) {
                $this->ingestor->delete($media);
            }
            throw $e;
        }

        // Avec une vidéo, la publication attend sa conversion avant de paraître (ProcessVideoHandler).
        if ($post->getMedia()->exists(static fn (int $i, MediaAttachment $m): bool => $m->isVideo())) {
            $post->hold();
        }
        $this->em->persist($post);
        $this->em->flush();

        $hasVideo = false;
        foreach ($post->getMedia() as $media) {
            if ($media->isVideo()) {
                $hasVideo = true;
                $this->bus->dispatch(new ProcessVideo($media->getId()->toRfc4122()));
            }
        }
        // Avec une vidéo, la publication est fédérée une fois la vidéo prête (ProcessVideoHandler).
        if (!$hasVideo) {
            $this->events->dispatch(new PostPublished($post));
        }

        return $post;
    }

    public function delete(Post $post): void
    {
        $this->events->dispatch(new PostDeleting($post));
        $media = $post->getMedia()->toArray();
        $this->em->remove($post);
        $this->em->flush();

        array_walk($media, fn (MediaAttachment $m) => $this->ingestor->delete($m));
    }
}
