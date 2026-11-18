<?php

declare(strict_types=1);

namespace App\Core\Domain\Notification;

use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\NotificationPreferencesRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Ce qu'un compte reçoit en notification sur ses appareils : nouvelles publications et stories des comptes suivis,
 * J'aime et commentaires sur ses publications, demandes d'abonnement à valider. Tout est activé par défaut.
 */
#[ORM\Entity(repositoryClass: NotificationPreferencesRepository::class)]
#[ORM\Table(name: 'notification_preferences')]
class NotificationPreferences
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(options: ['default' => true])]
    private bool $posts = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $stories = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $likes = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $comments = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $followRequests = true;

    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(unique: true, nullable: false, onDelete: 'CASCADE')]
        private Account $account,
    ) {
        $this->id = Uuid::v7();
    }

    public function choose(bool $posts, bool $stories, bool $likes, bool $comments, bool $followRequests = true): void
    {
        $this->followRequests = $followRequests;
        $this->posts = $posts;
        $this->stories = $stories;
        $this->likes = $likes;
        $this->comments = $comments;
    }

    public function wants(PushKind $kind): bool
    {
        return match ($kind) {
            PushKind::Post => $this->posts,
            PushKind::Story => $this->stories,
            PushKind::Like => $this->likes,
            PushKind::Comment => $this->comments,
            PushKind::FollowRequest => $this->followRequests,
        };
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function posts(): bool
    {
        return $this->posts;
    }

    public function stories(): bool
    {
        return $this->stories;
    }

    public function likes(): bool
    {
        return $this->likes;
    }

    public function comments(): bool
    {
        return $this->comments;
    }

    public function followRequests(): bool
    {
        return $this->followRequests;
    }
}
