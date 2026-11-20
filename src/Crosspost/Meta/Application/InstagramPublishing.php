<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Application;

use App\Core\Application\Post\Links;
use App\Core\Application\Post\Mentions;
use App\Core\Domain\Event\PostDeleting;
use App\Core\Domain\Event\PostPublished;
use App\Core\Domain\Event\PostUpdated;
use App\Core\Domain\Event\StoryPublished;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Post\CrosspostTarget;
use App\Core\Domain\Post\Post;
use App\Crosspost\Meta\Domain\CrosspostStatus;
use App\Crosspost\Meta\Domain\InstagramConnection;
use App\Crosspost\Meta\Domain\InstagramCrosspost;
use App\Crosspost\Meta\Infrastructure\Doctrine\InstagramCrosspostRepository;
use App\Crosspost\Meta\Infrastructure\InstagramClient;
use App\Crosspost\Meta\Infrastructure\InstagramMediaFiles;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Crosspost vers Instagram : une publication ou une story publiée avec « Publier aussi sur Instagram » est envoyée
 * en tâche de fond, par étapes (message PublishToInstagram renvoyé avec un délai entre deux étapes) :
 * 1. copies JPEG des photos, création des conteneurs (Instagram télécharge les médias) ;
 * 2. attente que les conteneurs soient prêts (vidéos), création du carrousel s'il y en a plusieurs ;
 * 3. publication, lien vers la publication Instagram (redemandé plus tard s'il manque).
 * L'API ne permet pas de modifier une publication Instagram. Avec la connexion unifiée (Facebook Login), elle permet
 * de la supprimer : à la suppression de la publication ici, et avant de la republier.
 */
final readonly class InstagramPublishing
{
    /** Délai entre deux vérifications de l'état des conteneurs. */
    public const int CHECK_DELAY = 30;
    /** Vérifications au plus (≈ 15 minutes) avant d'abandonner. */
    public const int MAX_CHECKS = 30;
    public const int MAX_CAPTION = 2200;
    public const int MAX_ALT = 1000;
    /** Lien de la publication Instagram redemandé au plus ce nombre de fois, toutes les minutes. */
    public const int PERMALINK_CHECKS = 5;

    public function __construct(
        private InstagramCrosspostRepository $crossposts,
        private InstagramConnections $connections,
        private InstagramClient $client,
        private InstagramMediaFiles $files,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        #[Autowire(param: 'app.local_domain')]
        private string $localDomain,
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

    /**
     * Envoi demandé après coup pendant qu'une vidéo ajoutée était en conversion : il part quand elle est prête
     * (ProcessVideoHandler envoie alors PostUpdated).
     */
    #[AsEventListener]
    public function onPostUpdated(PostUpdated $event): void
    {
        $post = $event->post;
        if (!$post->wantsCrosspost(CrosspostTarget::Instagram) || $post->isProcessing() || !$this->canSendExisting($post)) {
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
     * Publication supprimée : ses copies temporaires partent avec elle, et sa publication Instagram est supprimée.
     */
    #[AsEventListener]
    public function onPostDeleting(PostDeleting $event): void
    {
        $crosspost = $this->crossposts->forPost($event->post);
        if (!$crosspost instanceof InstagramCrosspost) {
            return;
        }
        $this->files->remove($crosspost->getId()->toBase58());
        // Supprimée ici : supprimée aussi sur Instagram, en tâche de fond (l'envoi part avec la publication).
        $mediaId = $crosspost->getInstagramMediaId();
        if (null !== $mediaId) {
            $this->bus->dispatch(new DeleteFromInstagram($crosspost->getAccount()->getId()->toRfc4122(), $mediaId));
        }
    }

    /**
     * Peut-on envoyer après coup cette publication déjà en ligne ? (rédigée ici, parue, non archivée, pas importée
     * d'Instagram, pas encore envoyée, compte Instagram connecté).
     */
    public function canSendExisting(Post $post): bool
    {
        return $post->getAccount()->isLocal()
            && !$post->isImported()
            && !$post->isPending()
            && !$post->isArchived()
            // Publication texte : Instagram n'accepte que des photos et des vidéos.
            && !$post->getMedia()->isEmpty()
            && !$this->crossposts->forPost($post) instanceof InstagramCrosspost
            && $this->connections->usable($post->getAccount()) instanceof InstagramConnection;
    }

    /**
     * Envoi après coup d'une publication déjà en ligne (menu ⋯, bouton de sa page, ou page Modifier). Avec une vidéo
     * encore en conversion (ajoutée en la modifiant), l'envoi est seulement noté : il part quand elle est prête.
     *
     * @return InstagramCrosspost|null null : en attente de la vidéo
     */
    public function sendExisting(Post $post): ?InstagramCrosspost
    {
        $existing = $this->crossposts->forPost($post);
        if ($existing instanceof InstagramCrosspost) {
            return $existing;
        }
        if (!$this->canSendExisting($post)) {
            throw new InstagramException(match (true) {
                $post->isImported() => 'Cette publication vient de votre archive Instagram : elle y est déjà.', $post->getMedia()->isEmpty() => 'Instagram n’accepte que des photos et des vidéos : une publication texte n’y part pas.', default => 'Cette publication ne peut pas être envoyée sur Instagram (en préparation, archivée, ou Instagram non connecté).',
            });
        }
        $post->addCrosspost(CrosspostTarget::Instagram);
        if ($post->isProcessing()) {
            $this->em->flush();

            return null;
        }
        $crosspost = InstagramCrosspost::forPost($post, $this->clock->now());
        $this->start($crosspost);

        return $crosspost;
    }

    /**
     * Message à la suppression d'une publication envoyée sur Instagram : sa copie y est supprimée aussi, ou reste.
     */
    public function forDeletion(Post $post): ?string
    {
        $crosspost = $this->crossposts->forPost($post);
        if (!$crosspost instanceof InstagramCrosspost || null === $crosspost->getInstagramMediaId()) {
            return null;
        }
        $connection = $this->connections->usable($post->getAccount());

        return $connection instanceof InstagramConnection && $connection->canDelete()
            ? 'Elle est supprimée aussi sur Instagram.'
            : 'Sa copie sur Instagram reste : supprimez-la dans l’application Instagram.';
    }

    /**
     * L'envoi peut-il être relancé ? (échec, publié, ou bloqué en cours depuis un quart d'heure).
     */
    public function canRelaunch(InstagramCrosspost $crosspost): bool
    {
        return $crosspost->isDone() || $crosspost->isStuck($this->clock->now());
    }

    /**
     * Relance un envoi : après un échec, s'il est bloqué, ou pour republier. Une publication Instagram précédente
     * est d'abord supprimée si l'API le permet (connexion unifiée).
     *
     * @return string|null message sur la publication Instagram précédente, s'il y en avait une
     */
    public function retry(InstagramCrosspost $crosspost): ?string
    {
        if (!$this->canRelaunch($crosspost)) {
            return null;
        }
        $previous = $crosspost->retry($this->clock->now());
        $notice = null === $previous ? null : $this->deletePrevious($crosspost, $previous);
        $this->start($crosspost);

        return $notice;
    }

    /**
     * Suppression d'une publication Instagram par l'API (connexion unifiée seulement).
     *
     * @throws InstagramException
     */
    public function deleteFromInstagram(InstagramConnection $connection, string $mediaId): void
    {
        if (!$connection->isUnifiedLogin()) {
            throw new InstagramException('Instagram ne permet de supprimer une publication par son API qu’avec la connexion unifiée');
        }
        if (!$connection->canDelete()) {
            throw new InstagramException('la configuration de l’application Meta n’accorde pas le droit instagram_manage_contents');
        }
        $this->client->deleteMedia($mediaId, $this->connections->token($connection));
    }

    private function deletePrevious(InstagramCrosspost $crosspost, string $mediaId): string
    {
        $connection = $this->connections->usable($crosspost->getAccount());
        if (!$connection instanceof InstagramConnection) {
            return 'La publication Instagram précédente n’a pas pu être supprimée (Instagram non connecté) : supprimez-la dans l’application Instagram.';
        }
        try {
            $this->deleteFromInstagram($connection, $mediaId);
        } catch (InstagramException $e) {
            return 'La publication Instagram précédente n’a pas été supprimée ('.rtrim($e->getMessage(), '.').') : supprimez-la dans l’application Instagram si elle y est encore.';
        }

        return 'La publication Instagram précédente a été supprimée.';
    }

    /**
     * Étape suivante d'un envoi ; rend le délai (en secondes) avant la suivante, ou null quand l'envoi est terminé.
     *
     * @throws InstagramException erreur temporaire, à réessayer par Messenger
     */
    public function advance(InstagramCrosspost $crosspost): ?int
    {
        if ($crosspost->awaitsPermalink()) {
            return $this->findPermalink($crosspost);
        }
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
        // Connexion unifiée : même API, jointe sur graph.facebook.com.
        $unified = $connection->isUnifiedLogin();
        $quota = $this->client->quota($connection->getInstagramUserId(), $token, $unified);
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
                $id = $this->client->createContainer($userId, $token, $params, $unified);
                $crosspost->sent([$id], $id, $files, $this->clock->now());
                $this->em->flush();

                return self::CHECK_DELAY;
            }

            $post = $crosspost->getPost() ?? throw new InstagramException('Cette publication n’existe plus.');
            $media = array_values(array_filter($post->getMedia()->toArray(), static fn (MediaAttachment $m): bool => $m->isReady()));
            if ([] === $media) {
                throw new InstagramException('Cette publication n’a pas de média prêt à envoyer.');
            }
            $caption = $this->caption($post->getCaption(), $post->getContentWarning(), $post->isImported());

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
                $id = $this->client->createContainer($userId, $token, array_filter($params, static fn (string $v): bool => '' !== $v), $unified);
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
                $children[] = $this->client->createContainer($userId, $token, $params, $unified);
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
        $unified = $connection->isUnifiedLogin();
        $userId = $connection->getInstagramUserId();
        $containerId = $crosspost->getContainerId();
        $waiting = null !== $containerId ? [$containerId] : $crosspost->getMediaContainers();

        foreach ($waiting as $id) {
            $status = $this->client->containerStatus($id, $token, $unified);
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
                'caption' => $this->caption($post->getCaption(), $post->getContentWarning(), $post->isImported()),
            ], static fn (string $v): bool => '' !== $v), $unified);
            $crosspost->carouselCreated($carousel, $this->clock->now());
            $this->em->flush();

            return 5;
        }

        $mediaId = $this->client->publish($userId, $token, $containerId, $unified);
        $crosspost->published($mediaId, $this->client->permalink($mediaId, $token, $unified), $this->clock->now());
        $this->em->flush();
        $this->files->remove($crosspost->getId()->toBase58());

        // Lien pas encore donné par Instagram : redemandé dans une minute.
        return $crosspost->awaitsPermalink() ? 60 : null;
    }

    /**
     * Lien de la publication Instagram, redemandé quand Instagram ne l'a pas donné tout de suite.
     */
    private function findPermalink(InstagramCrosspost $crosspost): ?int
    {
        $connection = $this->connections->usable($crosspost->getAccount());
        $mediaId = $crosspost->getInstagramMediaId();
        if (!$connection instanceof InstagramConnection || null === $mediaId || $crosspost->getChecks() >= self::PERMALINK_CHECKS) {
            return null;
        }
        $permalink = $this->client->permalink($mediaId, $this->connections->token($connection), $connection->isUnifiedLogin());
        $crosspost->permalinkFound($permalink, $this->clock->now());
        $this->em->flush();

        return null === $permalink ? 60 : null;
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
     * Légende pour Instagram : avertissement de contenu en tête ; « ig:@nom » devient une vraie mention Instagram ;
     * les comptes d'ici et du fédivers deviennent leur adresse en texte simple (« nom@instance »), sinon Instagram
     * mentionnerait le compte Instagram « @nom », qui n'est pas le même. Un texte importé d'Instagram reste tel quel.
     */
    private function caption(string $caption, ?string $contentWarning, bool $imported = false): string
    {
        $text = trim($caption);
        if (!$imported) {
            $text = Links::outside($text, fn (string $part): string => (string) preg_replace_callback(Mentions::PATTERN, fn (array $m): string => null !== $m[1]
                ? '@'.$m[2].(null !== $m[3] ? '@'.$m[3] : '')
                : $m[2].'@'.($m[3] ?? $this->localDomain), $part, flags: \PREG_UNMATCHED_AS_NULL));
        }
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
