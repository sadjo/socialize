<?php

declare(strict_types=1);

namespace App\Federation\Application;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Event\AccountFollowed;
use App\Core\Domain\Event\AccountUnfollowed;
use App\Core\Domain\Event\CommentAdded;
use App\Core\Domain\Event\PostDeleting;
use App\Core\Domain\Event\PostLiked;
use App\Core\Domain\Event\PostPublished;
use App\Core\Domain\Event\PostUnliked;
use App\Core\Domain\Event\ProfileUpdated;
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
    ) {
    }

    #[AsEventListener]
    public function onPostPublished(PostPublished $event): void
    {
        $post = $event->post;
        $author = $post->getAccount();
        if (!$author->isLocal() || Visibility::Private === $post->getVisibility()) {
            return;
        }
        $note = $this->serializer->note($post);
        $this->deliver($author, $this->followerInboxes($author), [
            'id' => $this->urls->note($post).'/activity',
            'type' => 'Create',
            'actor' => $this->urls->actor($author),
            'published' => $note['published'],
            'to' => $note['to'],
            'cc' => $note['cc'],
            'object' => $note,
        ]);
    }

    #[AsEventListener]
    public function onPostDeleting(PostDeleting $event): void
    {
        $post = $event->post;
        $author = $post->getAccount();
        if (!$author->isLocal() || Visibility::Private === $post->getVisibility()) {
            return;
        }
        [$to, $cc] = $this->serializer->addressing($post->getVisibility(), $author);
        $this->deliver($author, $this->followerInboxes($author), [
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
        $postAuthor = $comment->getPost()->getAccount();
        // Seules les réponses à une publication distante partent dans le fédivers pour l'instant.
        if (!$author->isLocal() || $postAuthor->isLocal()) {
            return;
        }
        $note = $this->serializer->commentNote($comment);
        $this->deliver($author, [$postAuthor->getInboxUrl(), ...$this->followerInboxes($author)], [
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
