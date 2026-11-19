<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Domain;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Account\AccountUser;
use App\Core\Domain\Account\MediaFilter;
use App\Core\Domain\Account\Theme;
use App\Core\Domain\Account\User;
use App\Integration\Mastodon\Infrastructure\Doctrine\MastodonUserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Personne connectée avec son compte Mastodon : elle pilote ce compte distant depuis Socialize, sans compte
 * sur l'instance. Le jeton d'accès à son serveur est chiffré au repos, et effacé à la déconnexion.
 */
#[ORM\Entity(repositoryClass: MastodonUserRepository::class)]
#[ORM\Table(name: 'mastodon_users')]
class MastodonUser implements AccountUser
{
    public const string ROLE = 'ROLE_MASTODON';
    /** Préfixe de l'identifiant de connexion, distinct des adresses e-mail et noms d'utilisateur de l'instance. */
    private const string IDENTIFIER_PREFIX = 'mastodon:';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** « mastodon:<nom>@<serveur> ». */
    #[ORM\Column(length: 300, unique: true)]
    private string $identifier;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $tokenEncrypted = null;

    /** Droits accordés par le serveur au jeton. */
    #[ORM\Column(length: 255)]
    private string $scopes = '';

    /**
     * Droits donnés par un administrateur de l'instance (import Instagram).
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastLoginAt;

    /** Visibilité par défaut de ses publications sur son serveur (public, unlisted, private, direct), à la connexion. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $defaultVisibility = null;

    /** Apparence choisie ici (Réglages › Apparence), comme pour un compte de l'instance. */
    #[ORM\Column(length: 16, enumType: Theme::class, options: ['default' => 'auto'])]
    private Theme $theme = Theme::Auto;

    #[ORM\Column(length: 16, enumType: MediaFilter::class, options: ['default' => 'light'])]
    private MediaFilter $mediaFilter = MediaFilter::Light;

    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(unique: true, nullable: false, onDelete: 'CASCADE')]
        private Account $account,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->identifier = self::identifierOf($account);
        $this->lastLoginAt = $createdAt;
    }

    public static function identifierOf(Account $account): string
    {
        return self::IDENTIFIER_PREFIX.$account->getAcct();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTheme(): Theme
    {
        return $this->theme;
    }

    public function chooseTheme(Theme $theme): void
    {
        $this->theme = $theme;
    }

    public function getMediaFilter(): MediaFilter
    {
        return $this->mediaFilter;
    }

    public function chooseMediaFilter(MediaFilter $filter): void
    {
        $this->mediaFilter = $filter;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->identifier);

        return $this->identifier;
    }

    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER', self::ROLE]));
    }

    public function isAdmin(): bool
    {
        return false;
    }

    /**
     * Import d'une archive Instagram vers son compte Mastodon, permis par un administrateur.
     */
    public function canImportInstagram(): bool
    {
        return \in_array(User::ROLE_INSTAGRAM_IMPORTER, $this->roles, true);
    }

    public function allowInstagramImport(bool $allow): void
    {
        $this->roles = $allow
            ? array_values(array_unique([...$this->roles, User::ROLE_INSTAGRAM_IMPORTER]))
            : array_values(array_filter($this->roles, static fn (string $role): bool => User::ROLE_INSTAGRAM_IMPORTER !== $role));
    }

    public function getTokenEncrypted(): ?string
    {
        return $this->tokenEncrypted;
    }

    public function getScopes(): string
    {
        return $this->scopes;
    }

    public function loggedIn(string $tokenEncrypted, string $scopes, \DateTimeImmutable $now, ?string $defaultVisibility = null): void
    {
        $this->tokenEncrypted = $tokenEncrypted;
        $this->scopes = mb_substr($scopes, 0, 255);
        $this->lastLoginAt = $now;
        $this->defaultVisibility = $defaultVisibility ?? $this->defaultVisibility;
    }

    public function getDefaultVisibility(): ?string
    {
        return $this->defaultVisibility;
    }

    public function loggedOut(): void
    {
        $this->tokenEncrypted = null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): \DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    /**
     * En session : le jeton n'y est jamais copié, seulement son empreinte. Après une déconnexion (jeton effacé), les
     * autres sessions de la personne se ferment : le fournisseur d'utilisateurs ne la trouve plus.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0tokenEncrypted"] = null === $this->tokenEncrypted ? null : hash('crc32c', $this->tokenEncrypted);

        return $data;
    }
}
