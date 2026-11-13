<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Core\Domain\Event\PostPublished;
use App\Core\Domain\Event\PostUpdated;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Media\MediaStatus;
use App\Core\Infrastructure\Doctrine\MediaAttachmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class ProcessVideoHandler
{
    public function __construct(
        private MediaAttachmentRepository $media,
        private MediaIngestor $ingestor,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ProcessVideo $message): void
    {
        $media = $this->media->find(Uuid::fromString($message->mediaId));
        if (null === $media || $media->isReady()) {
            return;
        }

        try {
            $this->ingestor->processVideo($media, fn (int $percent) => $this->media->saveProgress($media, $percent));
        } catch (MediaProcessingException $e) {
            $this->logger->warning('Échec du traitement vidéo', ['media' => $message->mediaId, 'exception' => $e]);
            $media->markFailed($e->getMessage());
        }

        $this->em->flush();
        // Progression écrite directement pendant la conversion : effacée de même (Doctrine ne la voit pas changer).
        $this->media->saveProgress($media, null);

        // Toutes les vidéos d'une publication sont traitées : elle peut partir dans le fédivers.
        $post = $media->getPost();
        if (null !== $post && !$post->getMedia()->exists(static fn (int $i, MediaAttachment $m): bool => MediaStatus::Processing === $m->getStatus())) {
            if ($post->isPending()) {
                // Publication en attente : elle paraît quand toutes ses vidéos sont prêtes. Si une conversion a échoué,
                // elle reste en attente : son auteur la voit, avec l'erreur, et peut la modifier ou la supprimer.
                if (!$post->getMedia()->exists(static fn (int $i, MediaAttachment $m): bool => MediaStatus::Failed === $m->getStatus())) {
                    $post->release($this->clock->now());
                    $this->em->flush();
                    $this->events->dispatch(new PostPublished($post));
                }
            } elseif (null !== $post->getUpdatedAt()) {
                // Vidéo ajoutée en modifiant la publication : c'est une mise à jour, pas une nouvelle publication.
                $this->events->dispatch(new PostUpdated($post, $post->getVisibility(), $post->getAudienceMembers()));
            } elseif ($post->shouldAnnounce()) {
                // Une publication importée sans annonce reste discrète.
                $this->events->dispatch(new PostPublished($post));
            }
        }
    }
}
