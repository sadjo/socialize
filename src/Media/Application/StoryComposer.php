<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Event\StoryPublished;
use App\Core\Domain\Music\MusicChoice;
use App\Core\Domain\Post\CrosspostTarget;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Social\AudienceGroup;
use App\Core\Domain\Story\Story;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Publie des stories : un fichier envoyé donne une story.
 */
final readonly class StoryComposer
{
    public function __construct(
        private EntityManagerInterface $em,
        private MediaIngestor $ingestor,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * @param list<File>            $files
     * @param MusicChoice|null      $music          musique de chaque story publiée
     * @param list<AudienceGroup>   $audienceGroups groupes d'abonnés (public « Groupes »)
     * @param list<CrosspostTarget> $crosspost      réseaux où envoyer aussi chaque story (Instagram…)
     *
     * @return list<Story>
     */
    public function publish(Account $author, array $files, ?string $text = null, Visibility $audience = Visibility::Public, ?MusicChoice $music = null, array $audienceGroups = [], array $crosspost = []): array
    {
        if ([] === $files) {
            throw new MediaProcessingException('Ajoutez au moins une photo ou une vidéo.');
        }
        if (\count($files) > Story::MAX_PER_UPLOAD) {
            throw new MediaProcessingException(\sprintf('Vous pouvez publier %d stories à la fois au plus.', Story::MAX_PER_UPLOAD));
        }

        $now = $this->clock->now();
        $stories = [];
        try {
            foreach ($files as $i => $file) {
                // Le texte va sur la première story ; chaque story suivante a une seconde de plus pour garder l'ordre.
                $story = new Story($author, $now->modify(\sprintf('+%d seconds', $i)), 0 === $i ? $text : null, $audience);
                $story->chooseMusic($music);
                $story->restrictTo($audienceGroups);
                $story->requestCrosspost($crosspost);
                $this->ingestor->attach($story, $file, null, 0);
                $stories[] = $story;
            }
        } catch (MediaProcessingException $e) {
            foreach ($stories as $story) {
                if ($story->getMedia() instanceof \App\Core\Domain\Media\MediaAttachment) {
                    $this->ingestor->delete($story->getMedia());
                }
            }
            throw $e;
        }

        foreach ($stories as $story) {
            $this->em->persist($story);
        }
        $this->em->flush();

        foreach ($stories as $story) {
            $media = $story->getMedia();
            if ($media instanceof \App\Core\Domain\Media\MediaAttachment && $media->isVideo()) {
                $this->bus->dispatch(new ProcessVideo($media->getId()->toRfc4122()));
            } else {
                // Avec une vidéo, la story est annoncée une fois la vidéo prête (ProcessVideoHandler).
                $this->events->dispatch(new StoryPublished($story));
            }
        }

        return $stories;
    }

    public function delete(Story $story): void
    {
        $media = $story->getMedia();
        $this->em->remove($story);
        $this->em->flush();

        if ($media instanceof \App\Core\Domain\Media\MediaAttachment) {
            $this->ingestor->delete($media);
        }
    }
}
