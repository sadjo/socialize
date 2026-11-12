<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Application;

use App\Core\Domain\Event\PostDeleting;
use App\Core\Domain\Event\PostPublished;
use App\Core\Domain\Event\StoryPublished;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Post\CrosspostTarget;
use App\Crosspost\Meta\Domain\CrosspostStatus;
use App\Crosspost\Meta\Domain\InstagramConnection;
use App\Crosspost\Meta\Domain\InstagramCrosspost;
use App\Crosspost\Meta\Infrastructure\Doctrine\InstagramCrosspostRepository;
use App\Crosspost\Meta\Infrastructure\InstagramClient;
use App\Crosspost\Meta\Infrastructure\InstagramMediaFiles;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Crosspost vers Instagram : une publication ou une story publiée avec « Publier aussi sur Instagram » est envoyée
 * en tâche de fond, par étapes (message PublishToInstagram renvoyé avec un délai entre deux étapes) :
 * 1. copies JPEG des photos, création des conteneurs (Instagram télécharge les médias) ;
 * 2. attente que les conteneurs soient prêts (vidéos), création du carrousel s'il y en a plusieurs ;
 * 3. publication, lien vers la publication Instagram.
 * Instagram ne permet ni de modifier ni de supprimer une publication par son API : l'envoi est fait une fois.
 */
final readonly class InstagramPublishing
{
    /** Délai entre deux vérifications de l'état des conteneurs. */
    public const int CHECK_DELAY = 30;
    /** Vérifications au plus (≈ 15 minutes) avant d'abandonner. */
    public const int MAX_CHECKS = 30;
    public const int MAX_CAPTION = 2200;
    public const int MAX_ALT = 1000;

    public function __construct(
        private InstagramCrosspostRepository $crossposts,
        private InstagramConnections $connections,
        private InstagramClient $client,
        private InstagramMediaFiles $files,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    #[AsEventListener]
    public function onPostPublished(PostPublished $event): void
    {
        $post = $event->post;
        if (!$post->getAccount()->isLocal() || $post->isImported() || !$post->wantsCrosspost(CrosspostTarget::Instagram) || $this->crossposts->forPost($post) instanceof InstagramCrosspost) {
            return;
        }
        $this->start(InstagramCrosspost::forPost($post, $this->clock->now()));
    }

    #[AsEventListener]
    public function onStoryPublished(StoryPublished $event): void
    {
        $story = $event->story;
        if (!$story->getAccount()->isLocal() || !$story->wantsCrosspost(CrosspostTarget::Instagram) || $this->crossposts->forStory($story) instanceof InstagramCrosspost) {
            return;
        }
        $this->start(InstagramCrosspost::forStory($story, $this->clock->now()));
    }

    /**
     * Publication supprimée pendant un envoi : ses copies temporaires partent avec elle.
     */
    #[AsEventListener]
    public function onPostDeleting(PostDeleting $event): void
    {
        $crosspost = $this->crossposts->forPost($event->post);
        if ($crosspost instanceof InstagramCrosspost) {
            $this->files->remove($crosspost->getId()->toBase58());
        }
    }

    public function retry(InstagramCrosspost $crosspost): void
    {
        if (CrosspostStatus::Failed !== $crosspost->getStatus()) {
            return;
        }
        $crosspost->retry($this->clock->now());
        $this->start($crosspost);
    }

    /**
     * Étape suivante d'un envoi ; rend le délai (en secondes) avant la suivante, ou null quand l'envoi est terminé.
     *
     * @throws InstagramException erreur temporaire, à réessayer par Messenger
     */
    public function advance(InstagramCrosspost $crosspost): ?int
    {
        if ($crosspost->isDone()) {
            return null;
        }
        $connection = $this->connections->usable($crosspost->getAccount());
        if (!$connection instanceof InstagramConnection) {
            return $this->fail($crosspost, 'Instagram n’est plus connecté : reconnectez-le dans Réglages › Instagram, puis réessayez.');
        }

        try {
            $token = $this->connections->token($connection);

            return CrosspostStatus::Pending === $crosspost->getStatus()
                ? $this->send($crosspost, $connection, $token)
                : $this->check($crosspost, $connection, $token);
        } catch (InstagramException $e) {
            $this->connections->noteFailure($connection, $e);
            if ($e->temporary) {
                throw $e;
            }

            return $this->fail($crosspost, $e->getMessage());
        }
    }

    private function start(InstagramCrosspost $crosspost): void
    {
        if (!$this->connections->usable($crosspost->getAccount()) instanceof InstagramConnection) {
            $this->em->persist($crosspost);
            $this->fail($crosspost, 'Instagram n’est pas connecté : connectez-le dans Réglages › Instagram, puis réessayez.');

            return;
        }
        $this->em->persist($crosspost);
        $this->em->flush();
        $this->bus->dispatch(new PublishToInstagram($crosspost->getId()->toRfc4122()));
    }

    /**
     * Étape 1 : copies et conteneurs.
     */
    private function send(InstagramCrosspost $crosspost, InstagramConnection $connection, string $token): int
    {
        $quota = $this->client->quota($connection->getInstagramUserId(), $token);
        if (null !== $quota['total'] && $quota['used'] >= $quota['total']) {
            throw new InstagramException(\sprintf('Quota de publications d’Instagram atteint (%d en 24 h) : réessayez plus tard.', $quota['total']));
        }

        $folder = $crosspost->getId()->toBase58();
        $userId = $connection->getInstagramUserId();
        $story = $crosspost->getStory();
        $files = [];
        try {
            if ($story instanceof \App\Core\Domain\Story\Story) {
                $media = $story->getMedia() ?? throw new InstagramException('Cette story n’a pas de média.');
                $params = ['media_type' => 'STORIES'];
                if ($media->isVideo()) {
                    $this->checkDuration($media, 3, 60, 'Une story Instagram dure de 3 à 60 secondes.');
                    $params['video_url'] = $this->files->videoUrl($media);
                } else {
                    $copy = $this->files->jpeg($media, $folder, 0, InstagramMediaFiles::STORY_RATIO);
                    $files[] = $copy['path'];
                    $params['image_url'] = $copy['url'];
                }
                $id = $this->client->createContainer($userId, $token, $params);
                $crosspost->sent([$id], $id, $files, $this->clock->now());
                $this->em->flush();

                return self::CHECK_DELAY;
            }

            $post = $crosspost->getPost() ?? throw new InstagramException('Cette publication n’existe plus.');
            $media = array_values(array_filter($post->getMedia()->toArray(), static fn (MediaAttachment $m): bool => $m->isReady()));
            if ([] === $media) {
                throw new InstagramException('Cette publication n’a pas de média prêt à envoyer.');
            }
            $caption = $this->caption($post->getCaption(), $post->getContentWarning());

            if (1 === \count($media)) {
                $only = $media[0];
                if ($only->isVideo()) {
                    $this->checkDuration($only, 3, 15 * 60, 'Un Reel Instagram dure de 3 secondes à 15 minutes.');
                    $params = ['media_type' => 'REELS', 'video_url' => $this->files->videoUrl($only), 'caption' => $caption, 'share_to_feed' => 'true'];
                } else {
                    $copy = $this->files->jpeg($only, $folder, 0, InstagramMediaFiles::feedRatio($only));
                    $files[] = $copy['path'];
                    $params = ['image_url' => $copy['url'], 'caption' => $caption] + $this->alt($only);
                }
                $id = $this->client->createContainer($userId, $token, array_filter($params, static fn (string $v): bool => '' !== $v));
                $crosspost->sent([$id], $id, $files, $this->clock->now());
                $this->em->flush();

                return self::CHECK_DELAY;
            }

            // Carrousel : Instagram recadre toutes les photos sur les proportions de la première ; elles y sont mises.
            $first = array_find($media, static fn (MediaAttachment $m): bool => !$m->isVideo());
            $ratio = $first instanceof MediaAttachment ? InstagramMediaFiles::feedRatio($first) : 1.0;
            $children = [];
            foreach ($media as $position => $item) {
                if ($item->isVideo()) {
                    $params = ['media_type' => 'VIDEO', 'video_url' => $this->files->videoUrl($item), 'is_carousel_item' => 'true'];
                } else {
                    $copy = $this->files->jpeg($item, $folder, $position, $ratio);
                    $files[] = $copy['path'];
                    $params = ['image_url' => $copy['url'], 'is_carousel_item' => 'true'] + $this->alt($item);
                }
                $children[] = $this->client->createContainer($userId, $token, $params);
            }
            $crosspost->sent($children, null, $files, $this->clock->now());
            $this->em->flush();

            return self::CHECK_DELAY;
        } catch (InstagramException $e) {
            if (!$e->temporary) {
                $this->files->remove($folder);
            }
            throw $e;
        }
    }

    /**
     * Étapes 2 et 3 : attente des conteneurs, carrousel, publication.
     */
    private function check(InstagramCrosspost $crosspost, InstagramConnection $connection, string $token): ?int
    {
        $userId = $connection->getInstagramUserId();
        $containerId = $crosspost->getContainerId();
        $waiting = null !== $containerId ? [$containerId] : $crosspost->getMediaContainers();

        foreach ($waiting as $id) {
            $status = $this->client->containerStatus($id, $token);
            if (\in_array($status, ['ERROR', 'EXPIRED'], true)) {
                return $this->fail($crosspost, 'EXPIRED' === $status
                    ? 'Instagram n’a pas publié à temps : réessayez.'
                    : 'Instagram a refusé un média (format, durée ou proportions). Les vidéos doivent durer au moins 3 secondes.');
            }
            if ('FINISHED' !== $status && 'PUBLISHED' !== $status) {
                $crosspost->checked($this->clock->now());
                $this->em->flush();

                return $crosspost->getChecks() >= self::MAX_CHECKS
                    ? $this->fail($crosspost, 'Instagram n’a pas fini de préparer les médias : réessayez plus tard.')
                    : self::CHECK_DELAY;
            }
        }

        if (null === $containerId) {
            $post = $crosspost->getPost() ?? throw new InstagramException('Cette publication n’existe plus.');
            $carousel = $this->client->createContainer($userId, $token, array_filter([
                'media_type' => 'CAROUSEL',
                'children' => implode(',', $crosspost->getMediaContainers()),
                'caption' => $this->caption($post->getCaption(), $post->getContentWarning()),
            ], static fn (string $v): bool => '' !== $v));
            $crosspost->carouselCreated($carousel, $this->clock->now());
            $this->em->flush();

            return 5;
        }

        $mediaId = $this->client->publish($userId, $token, $containerId);
        $crosspost->published($mediaId, $this->client->permalink($mediaId, $token), $this->clock->now());
        $this->em->flush();
        $this->files->remove($crosspost->getId()->toBase58());

        return null;
    }

    private function fail(InstagramCrosspost $crosspost, string $reason): null
    {
        $crosspost->fail($reason, $this->clock->now());
        $this->em->flush();
        $this->files->remove($crosspost->getId()->toBase58());

        return null;
    }

    private function checkDuration(MediaAttachment $video, int $min, int $max, string $rule): void
    {
        $duration = $video->getDuration();
        if (null !== $duration && ($duration < $min || $duration > $max)) {
            throw new InstagramException(\sprintf('%s Celle-ci dure %d s.', $rule, (int) round($duration)));
        }
    }

    /**
     * Légende pour Instagram : avertissement de contenu en tête, adresses du fédivers (@nom@instance) en texte simple
     * (sinon Instagram mentionnerait le compte Instagram « @nom », qui n'est pas le même).
     */
    private function caption(string $caption, ?string $contentWarning): string
    {
        $text = (string) preg_replace('/(?<![\w@])@([\w.]+)@([\w-]+(?:\.[\w-]+)+)/u', '$1@$2', trim($caption));
        if (null !== $contentWarning && '' !== trim($contentWarning)) {
            $text = '⚠️ '.trim($contentWarning)."\n\n".$text;
        }

        return mb_substr(trim($text), 0, self::MAX_CAPTION);
    }

    /**
     * @return array<string, string>
     */
    private function alt(MediaAttachment $media): array
    {
        $alt = trim((string) $media->getAltText());

        return '' !== $alt ? ['alt_text' => mb_substr($alt, 0, self::MAX_ALT)] : [];
    }
}
