<?php

declare(strict_types=1);

namespace App\Integration\Immich\Domain;

use App\Core\Domain\Account\Account;
use App\Integration\Immich\Infrastructure\Doctrine\ImmichConnectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Connexion d'un compte à son serveur Immich. La clé API est chiffrée au repos et jamais réaffichée.
 */
#[ORM\Entity(repositoryClass: ImmichConnectionRepository::class)]
#[ORM\Table(name: 'immich_connections')]
class ImmichConnection
{
    public const string ALBUM_NAME = 'Publié sur Socialize';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** Nom et adresse du compte Immich, version du serveur : relevés au dernier test. */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $immichUser = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $serverVersion = null;

    /** Ajouter les médias publiés à l'album « Publié sur Socialize ». */
    #[ORM\Column(options: ['default' => false])]
    private bool $addToAlbum = false;

    /** Identifiant Immich de cet album, une fois créé. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $albumId = null;

    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(unique: true, nullable: false, onDelete: 'CASCADE')]
        private Account $account,
        #[ORM\Column(length: 500)]
        private string $serverUrl,
        #[ORM\Column(type: Types::TEXT)]
        private string $apiKeyEncrypted,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $checkedAt,
    ) {
        $this->id = Uuid::v7();
        $this->serverUrl = self::normalizeUrl($serverUrl);
    }

    /**
     * « https://photos.maison.lan/ » ou « …/api » : l'adresse du serveur, sans barre finale ni /api.
     */
    public static function normalizeUrl(string $url): string
    {
        return (string) preg_replace('#/api$#', '', rtrim(trim($url), '/'));
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

    public function getApiKeyEncrypted(): string
    {
        return $this->apiKeyEncrypted;
    }

    public function change(string $serverUrl, string $apiKeyEncrypted): void
    {
        $serverUrl = self::normalizeUrl($serverUrl);
        if ($serverUrl !== $this->serverUrl) {
            // Autre serveur : l'album connu n'y existe pas.
            $this->albumId = null;
        }
        $this->serverUrl = $serverUrl;
        $this->apiKeyEncrypted = $apiKeyEncrypted;
    }

    public function checked(string $immichUser, string $serverVersion, \DateTimeImmutable $now): void
    {
        $this->immichUser = mb_substr($immichUser, 0, 200);
        $this->serverVersion = mb_substr($serverVersion, 0, 50);
        $this->checkedAt = $now;
    }

    public function getImmichUser(): ?string
    {
        return $this->immichUser;
    }

    public function getServerVersion(): ?string
    {
        return $this->serverVersion;
    }

    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function addsToAlbum(): bool
    {
        return $this->addToAlbum;
    }

    public function chooseAddToAlbum(bool $addToAlbum): void
    {
        $this->addToAlbum = $addToAlbum;
    }

    public function getAlbumId(): ?string
    {
        return $this->albumId;
    }

    public function rememberAlbum(?string $albumId): void
    {
        $this->albumId = $albumId;
    }
}
