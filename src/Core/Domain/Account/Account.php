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

    /** Identifiant ActivityPub de l'acteur distant (null pour un compte local : il est calculé). */
    #[ORM\Column(length: 500, unique: true, nullable: true)]
    private ?string $apUri = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $inboxUrl = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $sharedInboxUrl = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $outboxUrl = null;

    /** Page de profil HTML du compte distant. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $profileUrl = null;

    /** Photo de profil d'un compte distant (adresse sur son instance). */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $avatarUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $publicKeyPem = null;

    /** Clé privée d'un compte local, chiffrée (voir Federation\Infrastructure\KeyVault). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $privateKeyEncrypted = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fetchedAt = null;

    public function __construct(
        #[ORM\Column(length: 100)]
        private string $username,
        #[ORM\Column(length: 200)]
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
    public static function remote(string $username, string $domain, string $apUri): self
    {
        $account = new self($username, $username, mb_strtolower($domain));
        $account->apUri = $apUri;

        return $account;
    }

    /**
     * Met à jour un compte distant à partir de son acteur ActivityPub.
     */
    public function refreshRemote(
        string $displayName,
        ?string $bio,
        string $inboxUrl,
        ?string $sharedInboxUrl,
        ?string $outboxUrl,
        ?string $profileUrl,
        ?string $avatarUrl,
        ?string $publicKeyPem,
        \DateTimeImmutable $now,
    ): void {
        if ($this->isLocal()) {
            throw new \DomainException('Un compte local ne se met pas à jour depuis le fédivers.');
        }
        $this->displayName = mb_substr(trim($displayName), 0, 200);
        $bio = null !== $bio ? trim($bio) : null;
        $this->bio = '' === $bio ? null : $bio;
        $this->inboxUrl = $inboxUrl;
        $this->sharedInboxUrl = $sharedInboxUrl;
        $this->outboxUrl = $outboxUrl;
        $this->profileUrl = $profileUrl;
        $this->avatarUrl = $avatarUrl;
        $this->publicKeyPem = $publicKeyPem;
        $this->fetchedAt = $now;
    }

    public function getApUri(): ?string
    {
        return $this->apUri;
    }

    public function getInboxUrl(): ?string
    {
        return $this->inboxUrl;
    }

    /**
     * Boîte de réception où livrer : la boîte partagée de l'instance si elle existe.
     */
    public function getDeliveryInbox(): ?string
    {
        return $this->sharedInboxUrl ?? $this->inboxUrl;
    }

    public function getOutboxUrl(): ?string
    {
        return $this->outboxUrl;
    }

    public function getProfileUrl(): ?string
    {
        return $this->profileUrl;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function getPublicKeyPem(): ?string
    {
        return $this->publicKeyPem;
    }

    public function getPrivateKeyEncrypted(): ?string
    {
        return $this->privateKeyEncrypted;
    }

    public function getFetchedAt(): ?\DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    /**
     * Clés de signature d'un compte local, créées à la première fédération.
     */
    public function setKeys(string $publicKeyPem, string $privateKeyEncrypted): void
    {
        if (!$this->isLocal()) {
            throw new \DomainException('Seul un compte local possède une clé privée.');
        }
        $this->publicKeyPem = $publicKeyPem;
        $this->privateKeyEncrypted = $privateKeyEncrypted;
    }

    /**
     * « camille » pour un compte local, « camille@pixelfed.social » pour un compte distant.
     */
    public function getAcct(): string
    {
        return $this->isLocal() ? $this->username : $this->username.'@'.$this->domain;
    }

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
