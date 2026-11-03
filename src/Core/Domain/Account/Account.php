<?php

declare(strict_types=1);

namespace App\Core\Domain\Account;

use App\Core\Infrastructure\Doctrine\AccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Identité publique : compte local (domain = null) ou compte distant du fédivers.
 */
#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'accounts')]
#[ORM\UniqueConstraint(name: 'uniq_accounts_username_domain', columns: ['username', 'domain'])]
class Account
{
    /** Lettres minuscules, chiffres, point et tiret bas, comme Mastodon. */
    public const string USERNAME_PATTERN = '[a-z0-9_](?:[a-z0-9_.]{0,28}[a-z0-9_])?';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatarPath = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 30)]
        private string $username,
        #[ORM\Column(length: 100)]
        private string $displayName,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $domain = null,
    ) {
        $this->id = Uuid::v7();
        $this->username = mb_strtolower($username);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function isLocal(): bool
    {
        return null === $this->domain;
    }

    public function getDisplayName(): string
    {
        return '' !== $this->displayName ? $this->displayName : $this->username;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function getAvatarPath(): ?string
    {
        return $this->avatarPath;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updateProfile(string $displayName, ?string $bio): void
    {
        $this->displayName = trim($displayName);
        $bio = null !== $bio ? trim($bio) : null;
        $this->bio = '' === $bio ? null : $bio;
    }

    public function changeAvatar(?string $avatarPath): void
    {
        $this->avatarPath = $avatarPath;
    }

    /**
     * Initiales affichées quand le compte n'a pas de photo de profil.
     */
    public function getInitials(): string
    {
        $words = preg_split('/[\s._-]+/u', $this->getDisplayName(), -1, \PREG_SPLIT_NO_EMPTY) ?: [$this->username];
        $initials = '';
        foreach (\array_slice($words, 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $initials;
    }

    /**
     * Teinte stable (0-359) pour le dégradé de l'avatar par défaut.
     */
    public function getHue(): int
    {
        return hexdec(substr(hash('crc32b', $this->username.'@'.$this->domain), 0, 4)) % 360;
    }
}
