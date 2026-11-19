<?php

declare(strict_types=1);

namespace App\Federation\Application;

use App\Core\Domain\Event\PostDeleting;
use App\Core\Domain\Event\PostPublished;
use App\Core\Domain\Event\PostUpdated;
use App\Core\Domain\Post\Post;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Aperçus de liens des publications texte : demandé pour le premier lien du texte à la publication (locale ou reçue),
 * redemandé si ce lien change, et son image copiée supprimée quand elle ne sert plus. La page est lue en tâche de
 * fond (FetchLinkPreview).
 */
final readonly class LinkPreviews
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private FilesystemOperator $derivativesStorage,
    ) {
    }

    /**
     * Aperçu du premier lien d'une publication texte ; aucun pour une publication avec photos ou vidéos.
     *
     * @param bool $force relire la page même si le lien n'a pas changé (commande app:link-previews:fetch)
     */
    public function refresh(Post $post, bool $force = false): void
    {
        $url = $post->getMedia()->isEmpty() ? LinkPreviewParser::firstLink($post->getCaption()) : null;
        if (!$force && $url === $post->getPreviewUrl()) {
            return;
        }
        $this->removeImage($post);
        $post->requestLinkPreview($url);
        $this->em->flush();
        if (null !== $url) {
            $this->bus->dispatch(new FetchLinkPreview($post->getId()->toRfc4122()));
        }
    }

    #[AsEventListener]
    public function onPostPublished(PostPublished $event): void
    {
        $this->refresh($event->post);
    }

    #[AsEventListener]
    public function onPostUpdated(PostUpdated $event): void
    {
        $this->refresh($event->post);
    }

    #[AsEventListener]
    public function onPostDeleting(PostDeleting $event): void
    {
        $this->removeImage($event->post);
    }

    /**
     * Copie de l'image d'aperçu supprimée (publication supprimée, ou aperçu remplacé).
     */
    public function removeImage(Post $post): void
    {
        $image = $post->getPreviewImage();
        if (null === $image) {
            return;
        }
        try {
            $this->derivativesStorage->delete($image);
        } catch (FilesystemException) {
            // Fichier déjà absent : rien à faire.
        }
    }
}
