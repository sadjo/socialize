<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Core\Domain\Event\PostPublished;
use App\Core\Domain\Event\PostUpdated;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Media\MediaStatus;
use App\Core\Domain\Music\MusicChoice;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Social\AudienceGroup;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Modifie une publication : texte, visibilité, musique, et médias (ajout, retrait, ordre, texte alternatif).
 *
 * L'ordre est une liste de jetons : « m-<id> » pour un média existant, « n-<i> » pour le i-ème
 * nouveau fichier. Un média existant absent de la liste est retiré, avec ses fichiers.
 */
final readonly class PostEditor
{
    public function __construct(
        private EntityManagerInterface $em,
        private MediaIngestor $ingestor,
        private MessageBusInterface $bus,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string>           $order          jetons « m-… » et « n-… », dans l'ordre voulu
     * @param list<File>             $newFiles       nouveaux fichiers, référencés par « n-<index> »
     * @param array<string, ?string> $altTexts       texte alternatif par jeton
     * @param list<AudienceGroup>    $audienceGroups groupes d'abonnés (visibilité « Groupes »)
     *
     * @throws MediaProcessingException
     */
    public function edit(
        Post $post,
        string $caption,
        Visibility $visibility,
        ?string $contentWarning,
        ?string $location,
        array $order,
        array $newFiles = [],
        array $altTexts = [],
        ?MusicChoice $music = null,
        array $audienceGroups = [],
    ): void {
        $existing = [];
        foreach ($post->getMedia() as $media) {
            $existing['m-'.$media->getPublicId()] = $media;
        }

        $order = array_values(array_unique($order));
        foreach ($order as $token) {
            $valid = isset($existing[$token]) || (preg_match('/^n-(\d+)$/', $token, $m) && isset($newFiles[(int) $m[1]]));
            if (!$valid) {
                throw new MediaProcessingException('La liste des médias est invalide. Rechargez la page et réessayez.');
            }
        }
        if ([] === $order) {
            throw new MediaProcessingException('Une publication garde au moins une photo ou une vidéo.');
        }
        if (\count($order) > Post::MAX_MEDIA) {
            throw new MediaProcessingException(\sprintf('Une publication contient au plus %d médias.', Post::MAX_MEDIA));
        }

        $previousVisibility = $post->getVisibility();
        $previousAudience = $post->getAudienceMembers();
        $post->edit($caption, $visibility, $contentWarning, $location, $this->clock->now());
        $post->chooseMusic($music);
        $post->restrictTo($audienceGroups);

        // Retrait des médias absents de la nouvelle liste (les fichiers sont supprimés après l'enregistrement).
        $removed = [];
        foreach ($existing as $token => $media) {
            if (!\in_array($token, $order, true)) {
                $post->removeMedia($media);
                $removed[] = $media;
            }
        }

        $added = [];
        try {
            foreach ($order as $position => $token) {
                $alt = $altTexts[$token] ?? null;
                if (isset($existing[$token])) {
                    $existing[$token]->moveTo($position);
                    $existing[$token]->describe($alt);
                    continue;
                }
                $added[] = $this->ingestor->attach($post, $newFiles[(int) substr($token, 2)], $alt, $position);
            }
        } catch (MediaProcessingException $e) {
            foreach ($added as $media) {
                $this->ingestor->delete($media);
            }
            $this->em->refresh($post);
            throw $e;
        }

        $this->em->flush();

        foreach ($removed as $media) {
            $this->ingestor->delete($media);
        }
        $processing = false;
        foreach ($added as $media) {
            if ($media->isVideo()) {
                $processing = true;
                $this->bus->dispatch(new ProcessVideo($media->getId()->toRfc4122()));
            }
        }
        if ($post->isPending()) {
            // Encore en attente : elle paraît quand plus aucune vidéo n'est en conversion ni en échec (par exemple
            // après avoir retiré la vidéo qui n'a pas pu être convertie).
            $blocked = $post->getMedia()->exists(static fn (int $i, MediaAttachment $m): bool => MediaStatus::Ready !== $m->getStatus());
            if (!$blocked) {
                $post->release($this->clock->now());
                $this->em->flush();
                $this->events->dispatch(new PostPublished($post));
            }

            return;
        }
        // Avec une nouvelle vidéo, la mise à jour est fédérée une fois la vidéo prête (ProcessVideoHandler).
        if (!$processing) {
            $this->events->dispatch(new PostUpdated($post, $previousVisibility, $previousAudience));
        }
    }

    /**
     * Jeton d'un média existant, tel qu'attendu dans la liste d'ordre.
     */
    public static function token(MediaAttachment $media): string
    {
        return 'm-'.$media->getPublicId();
    }
}
