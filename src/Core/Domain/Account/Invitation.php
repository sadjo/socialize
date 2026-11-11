<?php

declare(strict_types=1);

namespace App\Core\Domain\Account;

use App\Core\Infrastructure\Doctrine\InvitationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Invitation à créer un compte, envoyée par e-mail ou par un lien à copier. À usage unique et limitée dans le temps,
 * elle permet de s'inscrire même quand les inscriptions sont fermées. Seule l'empreinte du jeton est gardée.
 */
#[ORM\Entity(repositoryClass: InvitationRepository::class)]
#[ORM\Table(name: 'invitations')]
#[ORM\Index(name: 'idx_invitations_created', columns: ['created_at'])]
class Invitation
{
    public const string LIFETIME = '+7 days';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Account $usedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        /** Empreinte SHA-256 du jeton du lien. */
        #[ORM\Column(length: 64, unique: true)]
        private string $tokenHash,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Account $invitedBy,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
        /** Adresse imposée au compte créé (invitation par e-mail), null pour un lien à copier. */
        #[ORM\Column(length: 180, nullable: true)]
        private ?string $email = null,
    ) {
        $this->id = Uuid::v7();
        $this->email = null !== $email ? mb_strtolower(trim($email)) : null;
        $this->expiresAt = $createdAt->modify(self::LIFETIME);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->id->toBase58();
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getInvitedBy(): ?Account
    {
        return $this->invitedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function getUsedBy(): ?Account
    {
        return $this->usedBy;
    }

    public function status(\DateTimeImmutable $now): InvitationStatus
    {
        return match (true) {
            $this->usedAt instanceof \DateTimeImmutable => InvitationStatus::Used,
            $this->revokedAt instanceof \DateTimeImmutable => InvitationStatus::Revoked,
            $this->expiresAt <= $now => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return InvitationStatus::Pending === $this->status($now);
    }

    public function use(Account $account, \DateTimeImmutable $now): void
    {
        if (!$this->isUsable($now)) {
            throw new \DomainException('Cette invitation n’est plus valable.');
        }
        $this->usedAt = $now;
        $this->usedBy = $account;
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        if (InvitationStatus::Pending === $this->status($now)) {
            $this->revokedAt = $now;
        }
    }
}
