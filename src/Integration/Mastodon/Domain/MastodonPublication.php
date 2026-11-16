<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Domain;

use App\Integration\Mastodon\Infrastructure\Doctrine\MastodonPublicationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Publication envoyée depuis Socialize sur un compte Mastodon : suivie le temps de l'envoi (en tâche de fond),
 * pour que son auteur voie qu'elle part, ou pourquoi elle n'est pas partie.
 */
#[ORM\Entity(repositoryClass: MastodonPublicationRepository::class)]
#[ORM\Table(name: 'mastodon_publications')]
#[ORM\Index(columns: ['created_at'])]
class MastodonPublication
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 20, enumType: MastodonPublicationState::class)]
    private MastodonPublicationState $state = MastodonPublicationState::Pending;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $error = null;

    /** Adresse ActivityPub de la publication sur le serveur Mastodon, une fois publiée. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $statusUri = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private MastodonUser $user,
        #[ORM\Column(length: 200)]
        private string $excerpt,
        #[ORM\Column]
        private int $mediaCount,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->excerpt = mb_substr($excerpt, 0, 200);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): MastodonUser
    {
        return $this->user;
    }

    public function getState(): MastodonPublicationState
    {
        return $this->state;
    }

    public function isPending(): bool
    {
        return MastodonPublicationState::Pending === $this->state;
    }

    public function isFailed(): bool
    {
        return MastodonPublicationState::Failed === $this->state;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getStatusUri(): ?string
    {
        return $this->statusUri;
    }

    public function getExcerpt(): string
    {
        return $this->excerpt;
    }

    public function getMediaCount(): int
    {
        return $this->mediaCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function sent(string $statusUri): void
    {
        $this->state = MastodonPublicationState::Sent;
        $this->statusUri = mb_substr($statusUri, 0, 500);
        $this->error = null;
    }

    public function failed(string $error): void
    {
        $this->state = MastodonPublicationState::Failed;
        $this->error = mb_substr($error, 0, 500);
    }
}
