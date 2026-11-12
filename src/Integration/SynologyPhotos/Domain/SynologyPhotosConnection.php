<?php

declare(strict_types=1);

namespace App\Integration\SynologyPhotos\Domain;

use App\Core\Domain\Account\Account;
use App\Integration\SynologyPhotos\Infrastructure\Doctrine\SynologyPhotosConnectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Connexion d'un compte à Synology Photos. Seule la session DSM est gardée, chiffrée au repos : le mot de passe
 * n'est jamais enregistré. Quand le NAS ne reconnaît plus la session, le compte doit se reconnecter.
 */
#[ORM\Entity(repositoryClass: SynologyPhotosConnectionRepository::class)]
#[ORM\Table(name: 'synology_photos_connections')]
class SynologyPhotosConnection
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** Session refusée par le NAS depuis cette date : reconnexion nécessaire. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiredAt = null;

    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(unique: true, nullable: false, onDelete: 'CASCADE')]
        private Account $account,
        #[ORM\Column(length: 500)]
        private string $serverUrl,
        /** Identifiant du compte DSM (pour le rappeler à la reconnexion). */
        #[ORM\Column(length: 200)]
        private string $userName,
        #[ORM\Column(type: Types::TEXT)]
        private string $sessionEncrypted,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $connectedAt,
    ) {
        $this->id = Uuid::v7();
        $this->serverUrl = self::normalizeUrl($serverUrl);
        $this->userName = mb_substr($userName, 0, 200);
    }

    /**
     * « https://photos.exemple.fr/ », « …/photo » ou « …/webapi/entry.cgi » : l'adresse du NAS, sans chemin d'API.
     */
    public static function normalizeUrl(string $url): string
    {
        return (string) preg_replace('#/(webapi(/entry\.cgi)?|photo)$#', '', rtrim(trim($url), '/'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getServerUrl(): string
    {
        return $this->serverUrl;
    }

    public function getUserName(): string
    {
        return $this->userName;
    }

    public function getSessionEncrypted(): string
    {
        return $this->sessionEncrypted;
    }

    public function getConnectedAt(): \DateTimeImmutable
    {
        return $this->connectedAt;
    }

    /**
     * Nouvelle session (connexion ou reconnexion).
     */
    public function reconnect(string $serverUrl, string $userName, string $sessionEncrypted, \DateTimeImmutable $now): void
    {
        $this->serverUrl = self::normalizeUrl($serverUrl);
        $this->userName = mb_substr($userName, 0, 200);
        $this->sessionEncrypted = $sessionEncrypted;
        $this->connectedAt = $now;
        $this->expiredAt = null;
    }

    public function isExpired(): bool
    {
        return $this->expiredAt instanceof \DateTimeImmutable;
    }

    public function expire(\DateTimeImmutable $now): void
    {
        $this->expiredAt ??= $now;
    }
}
