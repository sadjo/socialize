<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Notification\Notification;
use App\Core\Domain\Notification\NotificationType;
use App\Core\Domain\Post\Post;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
final class NotificationRepository extends ServiceEntityRepository
{
    public const int PAGE = 100;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Notifications du compte, les plus récentes d'abord.
     *
     * @return list<Notification>
     */
    public function of(Account $recipient, int $limit = self::PAGE): array
    {
        // findBy convertit les identifiants avec le bon type (UUID binaire ou natif selon la base).
        return $this->findBy(['recipient' => $recipient], ['createdAt' => 'DESC'], $limit);
    }

    public function unreadCount(Account $recipient): int
    {
        return $this->count(['recipient' => $recipient, 'readAt' => null]);
    }

    /**
     * Notification du « J'aime » d'un compte sur une publication (retirée quand le « J'aime » l'est).
     */
    public function forLike(Account $recipient, Account $actor, Post $post): ?Notification
    {
        return $this->findOneBy(['recipient' => $recipient, 'actor' => $actor, 'post' => $post, 'type' => NotificationType::Like]);
    }

    /**
     * @return list<Notification>
     */
    public function unreadOf(Account $recipient): array
    {
        return $this->findBy(['recipient' => $recipient, 'readAt' => null]);
    }

    /**
     * @return list<Notification>
     */
    public function allOf(Account $recipient): array
    {
        return $this->findBy(['recipient' => $recipient]);
    }
}
