<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use Doctrine\ORM\EntityManagerInterface;
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
    ) {
    }

    /**
     * @param list<array{file: File, alt: ?string}> $uploads
     */
    public function publish(
        Account $author,
        string $caption,
        array $uploads,
        Visibility $visibility = Visibility::Public,
        ?string $contentWarning = null,
        ?string $location = null,
    ): Post {
        if ([] === $uploads) {
            throw new MediaProcessingException('Ajoutez au moins une photo ou une vidéo.');
        }

        $post = new Post($author, $caption, $visibility, $contentWarning, $location);
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

        $this->em->persist($post);
        $this->em->flush();

        foreach ($post->getMedia() as $media) {
            if ($media->isVideo()) {
                $this->bus->dispatch(new ProcessVideo($media->getId()->toRfc4122()));
            }
        }

        return $post;
    }

    public function delete(Post $post): void
    {
        $media = $post->getMedia()->toArray();
        $this->em->remove($post);
        $this->em->flush();

        array_walk($media, fn (MediaAttachment $m) => $this->ingestor->delete($m));
    }
}
