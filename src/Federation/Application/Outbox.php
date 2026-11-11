<?php

declare(strict_types=1);

namespace App\Federation\Application;

use App\Core\Application\Post\PostAccess;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Event\AccountFollowed;
use App\Core\Domain\Event\AccountUnfollowed;
use App\Core\Domain\Event\CommentAdded;
use App\Core\Domain\Event\CommentDeleting;
use App\Core\Domain\Event\FollowerRemoved;
use App\Core\Domain\Event\PostArchived;
use App\Core\Domain\Event\PostDeleting;
use App\Core\Domain\Event\PostLiked;
use App\Core\Domain\Event\PostPublished;
use App\Core\Domain\Event\PostUnarchived;
use App\Core\Domain\Event\PostUnliked;
use App\Core\Domain\Event\PostUpdated;
use App\Core\Domain\Event\ProfileUpdated;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Transforme les actions des comptes locaux en activités ActivityPub et les envoie
 * aux instances concernées (via Messenger, en tâche de fond).
 */
final readonly class Outbox
{
    public function __construct(
        private Serializer $serializer,
        private ApUrls $urls,
        private FollowRepository $follows,
        private MessageBusInterface $bus,
        private EntityManagerInterface $em,
        private PostAccess $access,
    ) {
    }

    #[AsEventListener]
    public function onPostPublished(PostPublished $event): void
    {
        $post = $event->post;
        $post->getAccount();
        if (!$post->isFederable()) {
            return;
        }
        $this->sendCreate($post, $this->recipients($post, $post->getVisibility(), $this->access->groupAudience($post)));
    }

    /**
     * @param list<string> $inboxes
     */
    private function sendCreate(Post $post, array $inboxes): void
    {
        $author = $post->getAccount();
        $note = $this->serializer->note($post);
        $this->deliver($author, $inboxes, [
            'id' => $this->urls->note($post).'/activity',
            'type' => 'Create',
            'actor' => $this->urls->actor($author),
            'published' => $note['published'],
            'to' => $note['to'],
            'cc' => $note['cc'],
            'object' => $note,
        ]);
    }

    /**
     * Boîtes de réception à qui livrer une publication : ses membres distants abonnés (boîte personnelle) pour une
     * publication réservée à des groupes, sinon les abonnés distants.
     *
     * @param list<Account> $audience membres abonnés des groupes
     *
     * @return list<string>
     */
    private function recipients(Post $post, Visibility $visibility, array $audience): array
    {
        return match ($visibility) {
            Visibility::Private => [],
            Visibility::Groups => array_values(array_unique(array_filter(array_map(
                static fn (Account $m): ?string => $m->isLocal() ? null : ($m->getInboxUrl() ?? $m->getDeliveryInbox()),
                $audience,
            )))),
            default => $this->followerInboxes($post->getAccount()),
        };
    }

    /**
     * Modification : Update pour les abonnés ; Create si la publication sort du privé,
     * Delete si elle y entre (elle disparaît alors du fédivers).
     */
    #[AsEventListener]
    public function onPostUpdated(PostUpdated $event): void
    {
        $post = $event->post;
        $author = $post->getAccount();
        // Une publication archivée a déjà quitté le fédivers : elle y revient au désarchivage.
        if (!$author->isLocal() || $post->isArchived()) {
            return;
        }
        $wasFederated = Visibility::Private !== $event->previousVisibility;
        $isFederated = Visibility::Private !== $post->getVisibility();
        $previousAudience = array_values(array_filter($event->previousAudience, fn (Account $m): bool => $this->follows->isFollowing($m, $author)));
        $before = $this->recipients($post, $event->previousVisibility, $previousAudience);
        $after = $this->recipients($post, $post->getVisibility(), $this->access->groupAudience($post));

        if (!$wasFederated && $isFederated) {
            $this->sendCreate($post, $after);

            return;
        }
        if ($wasFederated && !$isFederated) {
            $this->sendDelete($post, $event->previousVisibility, $before, $previousAudience);
            $post->retireFromFediverse();
            $this->em->flush();

            return;
        }
        if (!$isFederated) {
            return;
        }

        // Destinataires qui changent (groupes, ou passage d'un groupe aux abonnés) : la publication apparaît chez
        // les nouveaux, est mise à jour chez ceux qui l'avaient, et disparaît chez ceux qui ne doivent plus la voir.
        $this->sendCreate($post, array_values(array_diff($after, $before)));
        $this->sendDelete($post, $event->previousVisibility, array_values(array_diff($before, $after)), $previousAudience);
        $note = $this->serializer->note($post);
        $this->deliver($author, array_values(array_intersect($after, $before)), [
            'id' => $this->urls->activity($author, 'update'),
            'type' => 'Update',
            'actor' => $this->urls->actor($author),
            'to' => $note['to'],
            'cc' => $note['cc'],
            'object' => $note,
        ]);
    }

    #[AsEventListener]
    public function onPostDeleting(PostDeleting $event): void
    {
        $post = $event->post;
        if (!$post->isFederable()) {
            return;
        }
        $this->sendCurrentDelete($post);
    }

    /**
     * Archivage : la publication quitte le fédivers ; son prochain envoi aura un nouvel identifiant.
     */
    #[AsEventListener]
    public function onPostArchived(PostArchived $event): void
    {
        $post = $event->post;
        if (!$post->getAccount()->isLocal() || Visibility::Private === $post->getVisibility()) {
            return;
        }
        $this->sendCurrentDelete($post);
        $post->retireFromFediverse();
        $this->em->flush();
    }

    #[AsEventListener]
    public function onPostUnarchived(PostUnarchived $event): void
    {
        $this->onPostPublished(new PostPublished($event->post));
    }

    private function sendCurrentDelete(Post $post): void
    {
        $audience = $this->access->groupAudience($post);
        $this->sendDelete($post, $post->getVisibility(), $this->recipients($post, $post->getVisibility(), $audience), $audience);
    }

    /**
     * @param list<string>  $inboxes
     * @param list<Account> $audience membres abonnés des groupes, pour l'adressage
     */
    private function sendDelete(Post $post, Visibility $visibility, array $inboxes, array $audience): void
    {
        $author = $post->getAccount();
        [$to, $cc] = $this->serializer->addressing($visibility, $author, $audience);
        $this->deliver($author, $inboxes, [
            'id' => $this->urls->activity($author, 'delete'),
            'type' => 'Delete',
            'actor' => $this->urls->actor($author),
            'to' => $to,
            'cc' => $cc,
            'object' => ['id' => $this->urls->note($post), 'type' => 'Tombstone'],
        ]);
    }

    #[AsEventListener]
    public function onAccountFollowed(AccountFollowed $event): void
    {
        $follow = $event->follow;
        $following = $follow->getFollowing();
        if ($following->isLocal() || !$follow->getFollower()->isLocal()) {
            return;
        }
        $follow->setApUri($this->urls->activity($follow->getFollower(), 'follow', $follow->getId()));
        $this->em->flush();

        $this->deliver($follow->getFollower(), [$following->getInboxUrl()], $this->followActivity((string) $follow->getApUri(), $follow->getFollower(), $following));
    }

    #[AsEventListener]
    public function onAccountUnfollowed(AccountUnfollowed $event): void
    {
        if ($event->following->isLocal() || !$event->follower->isLocal()) {
            return;
        }
        $this->deliver($event->follower, [$event->following->getInboxUrl()], [
            'id' => $this->urls->activity($event->follower, 'undo'),
            'type' => 'Undo',
            'actor' => $this->urls->actor($event->follower),
            'object' => $this->followActivity($event->followApUri ?? $this->urls->activity($event->follower, 'follow'), $event->follower, $event->following),
        ]);
    }

    /**
     * Abonné distant retiré : son instance reçoit un Reject de son abonnement (comme avec Mastodon).
     */
    #[AsEventListener]
    public function onFollowerRemoved(FollowerRemoved $event): void
    {
        if (!$event->account->isLocal() || $event->follower->isLocal()) {
            return;
        }
        $this->deliver($event->account, [$event->follower->getInboxUrl()], [
            'id' => $this->urls->activity($event->account, 'reject'),
            'type' => 'Reject',
            'actor' => $this->urls->actor($event->account),
            'object' => $this->followActivity($event->followApUri ?? $this->urls->activity($event->follower, 'follow'), $event->follower, $event->account),
        ]);
    }

    #[AsEventListener]
    public function onPostLiked(PostLiked $event): void
    {
        $like = $event->like;
        $author = $like->getPost()->getAccount();
        if ($author->isLocal() || !$like->getAccount()->isLocal()) {
            return;
        }
        $like->setApUri($this->urls->activity($like->getAccount(), 'like', $like->getId()));
        $this->em->flush();

        $this->deliver($like->getAccount(), [$author->getInboxUrl()], [
            'id' => $like->getApUri(),
            'type' => 'Like',
            'actor' => $this->urls->actor($like->getAccount()),
            'object' => $this->urls->note($like->getPost()),
        ]);
    }

    #[AsEventListener]
    public function onPostUnliked(PostUnliked $event): void
    {
        $author = $event->post->getAccount();
        if ($author->isLocal() || !$event->account->isLocal()) {
            return;
        }
        $this->deliver($event->account, [$author->getInboxUrl()], [
            'id' => $this->urls->activity($event->account, 'undo'),
            'type' => 'Undo',
            'actor' => $this->urls->actor($event->account),
            'object' => [
                'id' => $event->likeApUri ?? $this->urls->activity($event->account, 'like'),
                'type' => 'Like',
                'actor' => $this->urls->actor($event->account),
                'object' => $this->urls->note($event->post),
            ],
        ]);
    }

    #[AsEventListener]
    public function onCommentAdded(CommentAdded $event): void
    {
        $comment = $event->comment;
        $author = $comment->getAccount();
        $inboxes = $this->commentInboxes($comment);
        if (null === $inboxes) {
            return;
        }
        $note = $this->serializer->commentNote($comment);
        $this->deliver($author, $inboxes, [
            'id' => $this->urls->comment($comment).'/activity',
            'type' => 'Create',
            'actor' => $this->urls->actor($author),
            'published' => $note['published'],
            'to' => $note['to'],
            'cc' => $note['cc'],
            'object' => $note,
        ]);
    }

    #[AsEventListener]
    public function onCommentDeleting(CommentDeleting $event): void
    {
        $comment = $event->comment;
        $author = $comment->getAccount();
        $inboxes = $this->commentInboxes($comment);
        if (null === $inboxes) {
            return;
        }
        $note = $this->serializer->commentNote($comment);
        $this->deliver($author, $inboxes, [
            'id' => $this->urls->comment($comment).'#delete',
            'type' => 'Delete',
            'actor' => $this->urls->actor($author),
            'to' => $note['to'],
            'cc' => $note['cc'],
            'object' => ['id' => $this->urls->comment($comment), 'type' => 'Tombstone'],
        ]);
    }

    /**
     * Instances à prévenir pour le commentaire d'un compte local : l'auteur de la publication s'il est distant,
     * les abonnés distants du commentateur et ceux de l'auteur local de la publication.
     * Null si le commentaire ne quitte pas l'instance (commentaire distant, publication privée).
     *
     * @return list<string>|null
     */
    private function commentInboxes(\App\Core\Domain\Social\Comment $comment): ?array
    {
        $author = $comment->getAccount();
        $post = $comment->getPost();
        $postAuthor = $post->getAccount();
        if (!$author->isLocal() || Visibility::Private === $post->getVisibility() || $post->isArchived()) {
            return null;
        }
        // Publication réservée à des groupes : le commentaire reste entre ses membres (et son auteur).
        if (Visibility::Groups === $post->getVisibility()) {
            return array_values(array_filter([
                ...$this->recipients($post, Visibility::Groups, $this->access->groupAudience($post)),
                ...($postAuthor->isLocal() ? [] : [$postAuthor->getDeliveryInbox()]),
            ]));
        }

        return array_values(array_filter([
            ...$this->followerInboxes($author),
            ...($postAuthor->isLocal() ? $this->followerInboxes($postAuthor) : [$postAuthor->getDeliveryInbox()]),
        ]));
    }

    #[AsEventListener]
    public function onProfileUpdated(ProfileUpdated $event): void
    {
        $account = $event->account;
        if (!$account->isLocal()) {
            return;
        }
        $this->deliver($account, $this->followerInboxes($account), [
            'id' => $this->urls->activity($account, 'update'),
            'type' => 'Update',
            'actor' => $this->urls->actor($account),
            'to' => [ApUrls::PUBLIC],
            'object' => $this->serializer->actor($account),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function followActivity(string $id, Account $follower, Account $following): array
    {
        return [
            'id' => $id,
            'type' => 'Follow',
            'actor' => $this->urls->actor($follower),
            'object' => $this->urls->actor($following),
        ];
    }

    /**
     * @param list<string|null>    $inboxes
     * @param array<string, mixed> $activity
     */
    public function deliver(Account $signer, array $inboxes, array $activity): void
    {
        $json = json_encode($this->serializer->withContext($activity), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        foreach (array_unique(array_filter($inboxes)) as $inbox) {
            $this->bus->dispatch(new DeliverActivity($inbox, $json, $signer->getId()->toRfc4122()));
        }
    }

    /**
     * @return list<string>
     */
    private function followerInboxes(Account $account): array
    {
        return $this->follows->remoteFollowerInboxes($account);
    }
}
