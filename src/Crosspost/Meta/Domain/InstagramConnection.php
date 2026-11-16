<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Domain;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Visibility;
use App\Crosspost\Meta\Infrastructure\Doctrine\InstagramConnectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Compte Instagram professionnel relié à un compte Socialize pour le crosspost. Le jeton (60 jours, renouvelé
 * chaque semaine) est chiffré au repos. Règles : visibilités pour lesquelles « Publier aussi sur Instagram » est
 * coché par défaut à la création, et si elles valent aussi pour les stories.
 */
#[ORM\Entity(repositoryClass: InstagramConnectionRepository::class)]
#[ORM\Table(name: 'instagram_connections')]
class InstagramConnection
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $refreshedAt;

    /** Jeton refusé par Instagram depuis cette date : reconnexion nécessaire. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiredAt = null;

    /** @var list<string> visibilités (valeurs de Visibility) cochées par défaut */
    #[ORM\Column(type: Types::JSON)]
    private array $autoVisibilities = [Visibility::Public->value];

    /** Les règles valent aussi pour les stories. */
    #[ORM\Column(options: ['default' => false])]
    private bool $autoStories = false;

    /**
     * Connexion faite par la « connexion unifiée » de Meta (applications récentes) : l'API se joint alors sur
     * graph.facebook.com, et le jeton (utilisateur système) n'est pas renouvelé.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $unifiedLogin = false;

    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(unique: true, nullable: false, onDelete: 'CASCADE')]
        private Account $account,
        /** Identifiant du compte professionnel Instagram (« user_id »). */
        #[ORM\Column(length: 64)]
        private string $instagramUserId,
        #[ORM\Column(length: 100)]
        private string $username,
        #[ORM\Column(type: Types::TEXT)]
        private string $tokenEncrypted,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $tokenExpiresAt,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $connectedAt,
    ) {
        $this->id = Uuid::v7();
        $this->refreshedAt = $connectedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getInstagramUserId(): string
    {
        return $this->instagramUserId;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getTokenEncrypted(): string
    {
        return $this->tokenEncrypted;
    }

    public function getTokenExpiresAt(): \DateTimeImmutable
    {
        return $this->tokenExpiresAt;
    }

    public function getConnectedAt(): \DateTimeImmutable
    {
        return $this->connectedAt;
    }

    public function getRefreshedAt(): \DateTimeImmutable
    {
        return $this->refreshedAt;
    }

    /**
     * Nouvelle connexion (ou reconnexion) : nouveau compte et nouveau jeton.
     */
    public function reconnect(string $instagramUserId, string $username, string $tokenEncrypted, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now, bool $unifiedLogin = false): void
    {
        $this->unifiedLogin = $unifiedLogin;
        $this->instagramUserId = $instagramUserId;
        $this->username = $username;
        $this->renew($tokenEncrypted, $expiresAt, $now);
        $this->connectedAt = $now;
    }

    public function renew(string $tokenEncrypted, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): void
    {
        $this->tokenEncrypted = $tokenEncrypted;
        $this->tokenExpiresAt = $expiresAt;
        $this->refreshedAt = $now;
        $this->expiredAt = null;
    }

    public function viaUnifiedLogin(): void
    {
        $this->unifiedLogin = true;
    }

    public function isUnifiedLogin(): bool
    {
        return $this->unifiedLogin;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiredAt instanceof \DateTimeImmutable || $this->tokenExpiresAt <= $now;
    }

    public function expire(\DateTimeImmutable $now): void
    {
        $this->expiredAt ??= $now;
    }

    /**
     * @return list<Visibility>
     */
    public function getAutoVisibilities(): array
    {
        return array_values(array_filter(array_map(Visibility::tryFrom(...), $this->autoVisibilities)));
    }

    public function sharesStories(): bool
    {
        return $this->autoStories;
    }

    /**
     * @param list<Visibility> $visibilities
     */
    public function chooseRules(array $visibilities, bool $stories): void
    {
        $this->autoVisibilities = array_values(array_unique(array_map(static fn (Visibility $v): string => $v->value, $visibilities)));
        $this->autoStories = $stories;
    }

    /**
     * « Publier aussi sur Instagram » coché par défaut pour cette visibilité ?
     */
    public function checksByDefault(Visibility $visibility, bool $story = false): bool
    {
        return (!$story || $this->autoStories) && \in_array($visibility->value, $this->autoVisibilities, true);
    }
}
