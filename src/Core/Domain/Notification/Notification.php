<?php

declare(strict_types=1);

namespace App\Core\Domain\Notification;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Social\Comment;
use App\Core\Infrastructure\Doctrine\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Notification d'un compte local : quelqu'un (d'ici ou du fédivers) a aimé ou commenté une de ses publications.
 * Lue ou non lue ; supprimée avec la publication, le commentaire ou le compte qui l'a causée.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(name: 'idx_notifications_recipient_created', columns: ['recipient_id', 'created_at'])]
class Notification
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Account $recipient,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Account $actor,
        #[ORM\Column(length: 20, enumType: NotificationType::class)]
        private NotificationType $type,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Post $post,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?Comment $comment,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRecipient(): Account
    {
        return $this->recipient;
    }

    public function getActor(): Account
    {
        return $this->actor;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    public function getComment(): ?Comment
    {
        return $this->comment;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isRead(): bool
    {
        return $this->readAt instanceof \DateTimeImmutable;
    }

    public function markRead(\DateTimeImmutable $now): void
    {
        $this->readAt ??= $now;
    }

    public function markUnread(): void
    {
        $this->readAt = null;
    }

    public function isFor(Account $account): bool
    {
        return $this->recipient->getId()->equals($account->getId());
    }
}
