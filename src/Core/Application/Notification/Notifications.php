<?php

declare(strict_types=1);

namespace App\Core\Application\Notification;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Event\AccountRegistered;
use App\Core\Domain\Notification\Notification;
use App\Core\Domain\Notification\NotificationType;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Social\Comment;
use App\Core\Infrastructure\Doctrine\NotificationRepository;
use App\Core\Infrastructure\Doctrine\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Notifications des comptes locaux : « J'aime » et commentaires reçus sur leurs publications, venus d'ici ou du
 * fédivers (jamais pour ses propres actions) ; nouveaux comptes, pour les administrateurs. Lues ou non lues, supprimées une à une ou toutes ensemble.
 */
final readonly class Notifications
{
    public function __construct(
        private NotificationRepository $notifications,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private PushNotifications $push,
        private UserRepository $users,
    ) {
    }

    public function liked(Account $actor, Post $post): void
    {
        $recipient = $this->recipientFor($actor, $post);
        if ($recipient instanceof Account && !$this->notifications->forLike($recipient, $actor, $post) instanceof Notification) {
            $this->em->persist(new Notification($recipient, $actor, NotificationType::Like, $post, null, $this->clock->now()));
            $this->em->flush();
            $this->push->liked($recipient, $actor, $post);
        }
    }

    /**
     * « J'aime » retiré : sa notification aussi.
     */
    public function unliked(Account $actor, Post $post): void
    {
        $recipient = $this->recipientFor($actor, $post);
        $notification = $recipient instanceof Account ? $this->notifications->forLike($recipient, $actor, $post) : null;
        if ($notification instanceof Notification) {
            $this->em->remove($notification);
            $this->em->flush();
        }
    }

    public function commented(Comment $comment): void
    {
        $recipient = $this->recipientFor($comment->getAccount(), $comment->getPost());
        if ($recipient instanceof Account) {
            $this->em->persist(new Notification($recipient, $comment->getAccount(), NotificationType::Comment, $comment->getPost(), $comment, $this->clock->now()));
            $this->em->flush();
            $this->push->commented($recipient, $comment);
        }
    }

    /**
     * Nouveau compte : chaque administrateur est prévenu (sauf la personne elle-même, si elle est le premier compte).
     */
    #[AsEventListener]
    public function accountRegistered(AccountRegistered $event): void
    {
        $now = $this->clock->now();
        foreach ($this->users->admins() as $admin) {
            if (!$admin->getAccount()->getId()->equals($event->account->getId())) {
                $this->em->persist(new Notification($admin->getAccount(), $event->account, NotificationType::Signup, null, null, $now));
            }
        }
        $this->em->flush();
    }

    /**
     * @return list<Notification>
     */
    public function of(Account $account): array
    {
        return $this->notifications->of($account);
    }

    public function unreadCount(Account $account): int
    {
        return $this->notifications->unreadCount($account);
    }

    public function find(Account $account, string $id): ?Notification
    {
        $notification = \Symfony\Component\Uid\Uuid::isValid($id) ? $this->notifications->find(\Symfony\Component\Uid\Uuid::fromString($id)) : null;

        return $notification instanceof Notification && $notification->isFor($account) ? $notification : null;
    }

    public function markRead(Notification $notification): void
    {
        $notification->markRead($this->clock->now());
        $this->em->flush();
    }

    public function markUnread(Notification $notification): void
    {
        $notification->markUnread();
        $this->em->flush();
    }

    public function markAllRead(Account $account): void
    {
        $now = $this->clock->now();
        foreach ($this->notifications->unreadOf($account) as $notification) {
            $notification->markRead($now);
        }
        $this->em->flush();
    }

    public function delete(Notification $notification): void
    {
        $this->em->remove($notification);
        $this->em->flush();
    }

    public function deleteAll(Account $account): void
    {
        foreach ($this->notifications->allOf($account) as $notification) {
            $this->em->remove($notification);
        }
        $this->em->flush();
    }

    /**
     * Auteur local de la publication, s'il n'est pas lui-même l'auteur de l'action.
     */
    private function recipientFor(Account $actor, Post $post): ?Account
    {
        $author = $post->getAccount();

        return $author->isLocal() && !$author->getId()->equals($actor->getId()) ? $author : null;
    }
}
