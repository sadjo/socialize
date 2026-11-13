<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Domain\Media\MediaAttachment;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Core\Infrastructure\Doctrine\StoryRepository;
use App\Media\Infrastructure\VipsImageProcessor;
use App\Web\Security\CurrentAccount;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Fichiers d'une publication ou d'une story pour « Partager vers Instagram » (menu de partage du téléphone) :
 * photos en JPEG, vidéos en MP4. Réservés à l'auteur.
 */
final class ShareFileController extends AbstractController
{
    public function __construct(
        private readonly CurrentAccount $currentAccount,
        private readonly FilesystemOperator $derivativesStorage,
        private readonly VipsImageProcessor $images,
    ) {
    }

    #[Route('/p/{id}/partage/{position}', name: 'app_post_share_file', requirements: ['id' => Requirement::UID_BASE58, 'position' => '\d{1,2}'], methods: ['GET'])]
    public function post(string $id, int $position, PostRepository $posts): Response
    {
        $post = $posts->findByPublicId($id);
        if (!$post instanceof \App\Core\Domain\Post\Post || !$post->isOwnedBy($this->currentAccount->get())) {
            throw new NotFoundHttpException();
        }
        $media = array_values($post->getMedia()->toArray())[$position] ?? null;

        return $this->mediaFile($media, \sprintf('socialize-%s-%d', $id, $position + 1));
    }

    #[Route('/stories/{id}/partage', name: 'app_story_share_file', requirements: ['id' => Requirement::UID_BASE58], methods: ['GET'])]
    public function story(string $id, StoryRepository $stories): Response
    {
        $story = $stories->findByPublicId($id);
        if (!$story instanceof \App\Core\Domain\Story\Story || !$story->isOwnedBy($this->currentAccount->get())) {
            throw new NotFoundHttpException();
        }

        return $this->mediaFile($story->getMedia(), 'socialize-story-'.$id);
    }

    private function mediaFile(?MediaAttachment $media, string $name): Response
    {
        $path = $media?->getDerivative($media->isVideo() ? MediaAttachment::DERIVATIVE_VIDEO : MediaAttachment::DERIVATIVE_LARGE);
        if (!$media instanceof MediaAttachment || !$media->isReady() || null === $path) {
            throw new NotFoundHttpException('Média pas encore prêt.');
        }
        try {
            if ($media->isVideo()) {
                $stream = $this->derivativesStorage->readStream($path);
                $response = new StreamedResponse(static function () use ($stream): void {
                    fpassthru($stream);
                    fclose($stream);
                }, headers: ['Content-Type' => 'video/mp4', 'Content-Length' => (string) $this->derivativesStorage->fileSize($path)]);
                $name .= '.mp4';
            } else {
                $response = new Response($this->images->jpeg($this->derivativesStorage->read($path)), headers: ['Content-Type' => 'image/jpeg']);
                $name .= '.jpg';
            }
        } catch (FilesystemException $e) {
            throw new NotFoundHttpException('Média introuvable.', $e);
        }
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $name));
        $response->setPrivate();
        $response->setMaxAge(3600);

        return $response;
    }
}
