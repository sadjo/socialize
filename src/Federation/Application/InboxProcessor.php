<?php

declare(strict_types=1);

namespace App\Federation\Application;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Event\AccountFollowed;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Media\MediaType;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Social\Comment;
use App\Core\Domain\Social\Follow;
use App\Core\Domain\Social\FollowState;
use App\Core\Domain\Social\Like;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Core\Infrastructure\Doctrine\CommentRepository;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use App\Core\Infrastructure\Doctrine\LikeRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Federation\Infrastructure\FederationException;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Applique une activité reçue d'une autre instance : abonnements, likes, publications,
 * réponses, modifications et suppressions. Les activités inconnues sont ignorées.
 */
#[AsMessageHandler]
#[WithMonologChannel('federation')]
final readonly class InboxProcessor
{
    public function __construct(
        private EntityManagerInterface $em,
        private AccountRepository $accounts,
        private PostRepository $posts,
        private CommentRepository $comments,
        private FollowRepository $follows,
        private LikeRepository $likes,
        private RemoteAccounts $remote,
        private Outbox $outbox,
        private ApUrls $urls,
        private LoggerInterface $logger,
        private MessageBusInterface $bus,
        private \App\Core\Application\Notification\Notifications $notifications,
        private \App\Core\Application\Account\PilotedAccounts $piloted,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(ProcessInboxActivity $message): void
    {
        $actor = $this->accounts->find(Uuid::fromString($message->signerAccountId));
        /** @var array<string, mixed>|null $activity */
        $activity = json_decode($message->json, true);
        if (null === $actor || !\is_array($activity)) {
            return;
        }

        $this->process($activity, $actor);
    }

    /**
     * @param array<string, mixed> $activity
     */
    public function process(array $activity, Account $actor): void
    {
        $type = Json::string($activity, 'type');
        try {
            match ($type) {
                'Follow' => $this->follow($activity, $actor),
                'Accept' => $this->accept($activity, $actor),
                'Reject' => $this->reject($activity, $actor),
                'Undo' => $this->undo($activity, $actor),
                'Like' => $this->like($activity, $actor),
                'Create' => $this->create($activity, $actor),
                'Update' => $this->update($activity, $actor),
                'Delete' => $this->delete($activity, $actor),
                default => $this->logger->info('Activité ignorée', ['type' => $type, 'actor' => $actor->getApUri()]),
            };
        } catch (FederationException $e) {
            $this->logger->notice('Activité non traitée', ['type' => $type, 'actor' => $actor->getApUri(), 'reason' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function follow(array $activity, Account $actor): void
    {
        $target = $this->localAccount(Json::id($activity, 'object'));
        $id = Json::string($activity, 'id');
        if (!$target instanceof Account || null === $id) {
            return;
        }

        $follow = $this->follows->findBetween($actor, $target);
        $new = !$follow instanceof Follow;
        if (!$follow instanceof Follow) {
            $follow = new Follow($actor, $target, $target->manuallyApprovesFollowers() ? FollowState::Pending : FollowState::Accepted);
            $this->em->persist($follow);
        }
        $follow->setApUri($id);
        // Demande à valider par le compte : l'Accept partira quand il l'acceptera (Reject s'il la refuse).
        if (!$follow->isAccepted() && $target->manuallyApprovesFollowers()) {
            $this->em->flush();
            if ($new) {
                $this->events->dispatch(new AccountFollowed($follow));
            }

            return;
        }
        $follow->accept();
        $this->em->flush();

        $this->outbox->acceptFollow($target, $actor, $activity);
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function accept(array $activity, Account $actor): void
    {
        $follow = $this->ourFollowOf($activity, $actor);
        if ($follow instanceof Follow && !$follow->isAccepted()) {
            $follow->accept();
            $this->em->flush();
            // Abonnement accepté : on récupère l'historique récent du compte suivi.
            $this->bus->dispatch(new BackfillAccount($actor->getId()->toRfc4122()));
        }
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function reject(array $activity, Account $actor): void
    {
        $follow = $this->ourFollowOf($activity, $actor);
        if ($follow instanceof Follow) {
            $this->em->remove($follow);
            $this->em->flush();
        }
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function undo(array $activity, Account $actor): void
    {
        $object = Json::object($activity, 'object');
        $type = null !== $object ? Json::string($object, 'type') : null;
        $id = Json::id($activity, 'object');

        if ('Follow' === $type || (null !== $id && $this->follows->findByApUri($id) instanceof Follow)) {
            $follow = null !== $id ? $this->follows->findByApUri($id) : null;
            if (!$follow instanceof Follow && null !== $object) {
                $target = $this->localAccount(Json::id($object, 'object'));
                $follow = $target instanceof Account ? $this->follows->findBetween($actor, $target) : null;
            }
            if ($follow instanceof Follow && $follow->getFollower()->getId()->equals($actor->getId())) {
                $this->em->remove($follow);
                $this->em->flush();
                // Demande annulée avant d'être traitée : sa notification disparaît.
                $this->notifications->followRequestHandled($follow->getFollowing(), $actor);
            }

            return;
        }

        if ('Like' === $type) {
            $post = $this->localPost(Json::id($object, 'object'));
            $like = $post instanceof Post ? $this->likes->findOneFor($actor, $post) : null;
            if ($like instanceof Like) {
                $this->em->remove($like);
                $post->decrementLikes();
                $this->em->flush();
                $this->notifications->unliked($actor, $post);
            }
        }
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function like(array $activity, Account $actor): void
    {
        $post = $this->localPost(Json::id($activity, 'object'));
        if (!$post instanceof Post || $this->likes->findOneFor($actor, $post) instanceof Like) {
            return;
        }
        $like = new Like($actor, $post);
        $like->setApUri(Json::string($activity, 'id'));
        $this->em->persist($like);
        $post->incrementLikes();
        $this->em->flush();
        $this->notifications->liked($actor, $post);
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function create(array $activity, Account $actor): void
    {
        $note = Json::object($activity, 'object');
        if (null === $note || 'Note' !== Json::string($note, 'type') && 'Image' !== Json::string($note, 'type')) {
            return;
        }
        $id = Json::string($note, 'id');
        if (null === $id || Json::host($id) !== $actor->getDomain() || Json::id($note, 'attributedTo') !== $actor->getApUri()) {
            throw new FederationException('Objet attribué à un autre compte que le signataire.');
        }
        if (null !== $this->posts->findOneBy(['apUri' => $id]) || null !== $this->comments->findOneBy(['apUri' => $id])) {
            return;
        }

        $inReplyTo = Json::id($note, 'inReplyTo');
        if (null !== $inReplyTo) {
            $this->reply($note, $id, $inReplyTo, $actor);

            return;
        }

        $visibility = $this->visibility($note);
        // Seules les publications des comptes suivis, qui mentionnent un compte local, ou pilotés d'ici (connexion
        // Mastodon : son profil) sont gardées.
        if (!$visibility instanceof Visibility || (!$this->follows->hasLocalFollower($actor) && !$this->mentionsLocal($note) && !$this->piloted->isPiloted($actor))) {
            return;
        }

        $attachments = $this->attachments($note);
        if ([] === $attachments) {
            return; // Socialize est un réseau photo : les messages sans image ni vidéo sont ignorés.
        }

        $post = Post::federated(
            $actor,
            HtmlText::toText(Json::string($note, 'content')),
            $visibility,
            (bool) ($note['sensitive'] ?? false) ? (Json::string($note, 'summary') ?? 'Contenu sensible') : Json::string($note, 'summary'),
            $id,
            Json::id($note, 'url') ?? $id,
            $this->date(Json::string($note, 'published')),
        );
        foreach ($attachments as $position => $a) {
            MediaAttachment::remote($post, $a['type'], $position, $a['url'], $a['mediaType'], $a['name'], $a['width'], $a['height'], $a['blurhash'], $a['preview']);
        }
        $this->em->persist($post);
        $this->em->flush();
        $this->notifications->mentioned($post, $this->mentionedLocals($note));
    }

    /**
     * Comptes de cette instance mentionnés par une note du fédivers (balises Mention).
     *
     * @param array<string, mixed> $note
     *
     * @return list<Account>
     */
    private function mentionedLocals(array $note): array
    {
        $accounts = [];
        foreach (Json::list($note, 'tag') as $tag) {
            if (\is_array($tag) && 'Mention' === ($tag['type'] ?? null)) {
                $account = $this->localAccount(\is_string($tag['href'] ?? null) ? $tag['href'] : null);
                if ($account instanceof Account) {
                    $accounts[] = $account;
                }
            }
        }

        return $accounts;
    }

    /**
     * @param array<string, mixed> $note
     */
    private function reply(array $note, string $id, string $inReplyTo, Account $actor): void
    {
        $post = $this->localPost($inReplyTo) ?? $this->posts->findOneBy(['apUri' => $inReplyTo]);
        if (null === $post) {
            return;
        }
        $content = HtmlText::toText(Json::string($note, 'content'));
        // Retire la mention de l'auteur en tête de réponse (« @camille Superbe ! »).
        $content = trim((string) preg_replace('/^(@[\w.\-]+(@[\w.\-]+)?\s+)+/u', '', $content));
        if ('' === $content) {
            return;
        }
        $comment = Comment::federated($post, $actor, mb_substr($content, 0, Comment::MAX_LENGTH), $id, $this->date(Json::string($note, 'published')));
        $this->em->persist($comment);
        $post->incrementComments();
        $this->em->flush();
        $this->notifications->commented($comment);
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function update(array $activity, Account $actor): void
    {
        $object = Json::object($activity, 'object');
        if (null === $object) {
            return;
        }
        $type = Json::string($object, 'type');
        if (\in_array($type, ['Person', 'Service', 'Application', 'Group', 'Organization'], true) && Json::string($object, 'id') === $actor->getApUri()) {
            $this->remote->byUri((string) $actor->getApUri(), true);

            return;
        }
        if ('Note' === $type) {
            $post = $this->posts->findOneBy(['apUri' => Json::string($object, 'id')]);
            if (null !== $post && $post->getAccount()->getId()->equals($actor->getId())) {
                $post->updateFederated(HtmlText::toText(Json::string($object, 'content')), Json::string($object, 'summary'));
                $this->em->flush();
            }
        }
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function delete(array $activity, Account $actor): void
    {
        $id = Json::id($activity, 'object');
        if (null === $id) {
            return;
        }
        if ($id === $actor->getApUri()) {
            $this->em->remove($actor); // compte supprimé sur son instance : ses publications, likes et abonnements partent avec lui
            $this->em->flush();

            return;
        }

        $post = $this->posts->findOneBy(['apUri' => $id]);
        if (null !== $post && $post->getAccount()->getId()->equals($actor->getId())) {
            $this->em->remove($post);
            $this->em->flush();

            return;
        }
        $comment = $this->comments->findOneBy(['apUri' => $id]);
        if (null !== $comment && $comment->getAccount()->getId()->equals($actor->getId())) {
            $comment->getPost()->decrementComments();
            $this->em->remove($comment);
            $this->em->flush();
        }
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function ourFollowOf(array $activity, Account $actor): ?Follow
    {
        $object = Json::object($activity, 'object');
        $id = Json::id($activity, 'object');
        $follow = null !== $id ? $this->follows->findByApUri($id) : null;
        if (!$follow instanceof Follow && null !== $object) {
            $follower = $this->localAccount(Json::id($object, 'actor'));
            $follow = $follower instanceof Account ? $this->follows->findBetween($follower, $actor) : null;
        }

        return $follow instanceof Follow && $follow->getFollowing()->getId()->equals($actor->getId()) ? $follow : null;
    }

    private function localAccount(?string $uri): ?Account
    {
        if (null === $uri || !$this->urls->isLocal($uri) || !preg_match('#/users/([a-z0-9_.]+)$#', $uri, $m)) {
            return null;
        }

        return $this->accounts->findLocal($m[1]);
    }

    private function localPost(?string $uri): ?Post
    {
        if (null === $uri || !$this->urls->isLocal($uri) || !preg_match('#/p/([1-9A-HJ-NP-Za-km-z]{22})(?:/v\d+)?$#', $uri, $m)) {
            return null;
        }

        return $this->posts->findByPublicId($m[1]);
    }

    /**
     * @param array<string, mixed> $note
     */
    private function visibility(array $note): ?Visibility
    {
        $to = Json::audience($note, 'to');
        $cc = Json::audience($note, 'cc');
        $public = [ApUrls::PUBLIC, 'as:Public', 'Public'];

        return match (true) {
            [] !== array_intersect($public, $to) => Visibility::Public,
            [] !== array_intersect($public, $cc) => Visibility::Unlisted,
            [] !== array_filter([...$to, ...$cc], static fn (string $a): bool => str_ends_with($a, '/followers')) => Visibility::Followers,
            default => null, // message direct : pas pris en charge
        };
    }

    /**
     * @param array<string, mixed> $note
     */
    private function mentionsLocal(array $note): bool
    {
        return array_any(Json::list($note, 'tag'), fn ($tag): bool => \is_array($tag) && 'Mention' === ($tag['type'] ?? null) && $this->localAccount(\is_string($tag['href'] ?? null) ? $tag['href'] : null) instanceof Account);
    }

    /**
     * @param array<string, mixed> $note
     *
     * @return list<array{type: MediaType, url: string, mediaType: string, name: ?string, width: ?int, height: ?int, blurhash: ?string, preview: ?string}>
     */
    private function attachments(array $note): array
    {
        $out = [];
        foreach (Json::list($note, 'attachment') as $a) {
            if (!\is_array($a) || \count($out) >= Post::MAX_MEDIA) {
                continue;
            }
            $url = Json::id($a, 'url');
            $mediaType = Json::string($a, 'mediaType') ?? '';
            $kind = match (true) {
                str_starts_with($mediaType, 'image/') || 'Image' === ($a['type'] ?? null) => MediaType::Image,
                str_starts_with($mediaType, 'video/') || 'Video' === ($a['type'] ?? null) => MediaType::Video,
                default => null,
            };
            if (null === $url || null === $kind || !str_starts_with($url, 'https://')) {
                continue;
            }
            $icon = $a['icon'] ?? null; // vignette : adresse, ou objet Image avec une « url »
            $preview = \is_string($icon) ? $icon : (\is_array($icon) && !array_is_list($icon) ? Json::id(Json::keyed($icon), 'url') : null);
            $out[] = [
                'type' => $kind,
                'url' => $url,
                'mediaType' => '' !== $mediaType ? $mediaType : (MediaType::Video === $kind ? 'video/mp4' : 'image/jpeg'),
                'name' => Json::string($a, 'name'),
                'width' => \is_int($a['width'] ?? null) ? $a['width'] : null,
                'height' => \is_int($a['height'] ?? null) ? $a['height'] : null,
                'blurhash' => Json::string($a, 'blurhash'),
                'preview' => null !== $preview && str_starts_with($preview, 'https://') ? $preview : null,
            ];
        }

        return $out;
    }

    private function date(?string $value): \DateTimeImmutable
    {
        try {
            $date = null !== $value ? new \DateTimeImmutable($value) : new \DateTimeImmutable();
        } catch (\Exception) {
            $date = new \DateTimeImmutable();
        }

        return $date > new \DateTimeImmutable() ? new \DateTimeImmutable() : $date->setTimezone(new \DateTimeZone('UTC'));
    }
}
