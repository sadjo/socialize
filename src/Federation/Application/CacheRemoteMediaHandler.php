<?php

declare(strict_types=1);

namespace App\Federation\Application;

use App\Core\Domain\Media\MediaAttachment;
use App\Core\Infrastructure\Doctrine\MediaAttachmentRepository;
use App\Federation\Infrastructure\FederationClient;
use App\Federation\Infrastructure\FederationException;
use App\Media\Application\MediaIngestor;
use App\Media\Application\MediaProcessingException;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Copies locales d'un média du fédivers : le fil sert alors des images à la bonne taille, en WebP et gardées en cache
 * par le navigateur, au lieu de l'original en pleine taille chargé depuis son instance. En cas d'échec, l'adresse
 * d'origine reste servie.
 */
#[AsMessageHandler]
#[WithMonologChannel('federation')]
final readonly class CacheRemoteMediaHandler
{
    /** Taille maximale d'une image téléchargée. */
    public const int MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private MediaAttachmentRepository $media,
        private FederationClient $client,
        private MediaIngestor $ingestor,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CacheRemoteMedia $message): void
    {
        $media = $this->media->find(Uuid::fromString($message->mediaId));
        if (!$media instanceof MediaAttachment || !$media->isRemote() || $media->hasLocalCopies()) {
            return;
        }
        $source = self::sourceOf($media);
        if (null === $source) {
            return;
        }

        try {
            $this->ingestor->cacheRemote($media, $this->client->downloadImage($source, self::MAX_BYTES));
        } catch (FederationException|MediaProcessingException|FilesystemException $e) {
            $this->logger->warning('Média du fédivers non copié : son adresse d’origine reste servie', ['media' => $message->mediaId, 'url' => $source, 'exception' => $e]);

            return;
        }
        $this->em->flush();
    }

    /**
     * Image à télécharger : le média lui-même, ou l'affiche fournie par l'instance pour une vidéo (null sans affiche).
     */
    public static function sourceOf(MediaAttachment $media): ?string
    {
        if (!$media->isVideo()) {
            return $media->getRemoteUrl();
        }
        $poster = $media->getDerivative(MediaAttachment::DERIVATIVE_POSTER);

        return null !== $poster && str_contains($poster, '://') ? $poster : null;
    }
}
