<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Core\Infrastructure\Doctrine\MediaAttachmentRepository;
use Doctrine\ORM\EntityManagerInterface;
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
    ) {
    }

    public function __invoke(ProcessVideo $message): void
    {
        $media = $this->media->find(Uuid::fromString($message->mediaId));
        if (null === $media || $media->isReady()) {
            return;
        }

        try {
            $this->ingestor->processVideo($media);
        } catch (MediaProcessingException $e) {
            $this->logger->warning('Échec du traitement vidéo', ['media' => $message->mediaId, 'exception' => $e]);
            $media->markFailed($e->getMessage());
        }

        $this->em->flush();
    }
}
