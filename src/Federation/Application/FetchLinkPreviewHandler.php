<?php

declare(strict_types=1);

namespace App\Federation\Application;

use App\Core\Domain\Post\LinkPreviewState;
use App\Core\Domain\Post\Post;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Federation\Infrastructure\FederationClient;
use App\Federation\Infrastructure\FederationException;
use App\Media\Application\MediaProcessingException;
use App\Media\Infrastructure\VipsImageProcessor;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Aperçu du lien d'une publication texte : lit le début de la page (client protégé contre les adresses du réseau
 * privé), en tire site, titre et description, et copie ici son image en petit WebP : les visiteurs ne chargent rien
 * depuis le site du lien. Page illisible : pas d'aperçu ; image illisible : aperçu sans image.
 */
#[AsMessageHandler]
#[WithMonologChannel('federation')]
final readonly class FetchLinkPreviewHandler
{
    public const int MAX_HTML_BYTES = 1_000_000;
    public const int MAX_IMAGE_BYTES = 8 * 1024 * 1024;
    private const int IMAGE_WIDTH = 480;

    public function __construct(
        private PostRepository $posts,
        private FederationClient $client,
        private VipsImageProcessor $images,
        private FilesystemOperator $derivativesStorage,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FetchLinkPreview $message): void
    {
        $post = $this->posts->find(Uuid::fromString($message->postId));
        $url = $post?->getPreviewUrl();
        if (!$post instanceof Post || null === $url || LinkPreviewState::Pending !== $post->getPreviewState()) {
            return;
        }

        try {
            $page = LinkPreviewParser::parse($this->client->fetchHtml($url, self::MAX_HTML_BYTES), $url);
        } catch (FederationException $e) {
            $this->logger->info('Aperçu de lien impossible', ['url' => $url, 'reason' => $e->getMessage()]);
            $post->failLinkPreview();
            $this->em->flush();

            return;
        }

        $post->completeLinkPreview($page['site'], $page['title'], $page['description'], null !== $page['image'] ? $this->copyImage($post, $page['image']) : null);
        $this->em->flush();
    }

    /**
     * @return string|null chemin de la copie locale, null si l'image n'a pas pu être copiée
     */
    private function copyImage(Post $post, string $url): ?string
    {
        try {
            $webp = $this->images->contentImage($this->client->downloadImage($url, self::MAX_IMAGE_BYTES), self::IMAGE_WIDTH);
            $path = \sprintf('previews/%s-%s.webp', $post->getId()->toRfc4122(), bin2hex(random_bytes(4)));
            $this->derivativesStorage->write($path, $webp);

            return $path;
        } catch (FederationException|MediaProcessingException|FilesystemException $e) {
            $this->logger->info('Image d’aperçu non copiée : aperçu sans image', ['url' => $url, 'reason' => $e->getMessage()]);

            return null;
        }
    }
}
