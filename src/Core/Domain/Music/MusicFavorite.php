<?php

declare(strict_types=1);

namespace App\Core\Domain\Music;

use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\MusicFavoriteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Morceau d'Openverse mis en favori par un compte, proposé à l'ouverture du sélecteur de musique.
 * Seule la fiche est gardée (titre, artiste, licence…) : le fichier n'est copié qu'à la publication.
 */
#[ORM\Entity(repositoryClass: MusicFavoriteRepository::class)]
#[ORM\Table(name: 'music_favorites')]
#[ORM\UniqueConstraint(name: 'uniq_music_favorites_account_track', columns: ['account_id', 'openverse_id'])]
#[ORM\Index(name: 'idx_music_favorites_account_created', columns: ['account_id', 'created_at'])]
class MusicFavorite
{
    public const int MAX_PER_ACCOUNT = 200;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $genres
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Account $account,
        #[ORM\Column(length: 64)]
        private string $openverseId,
        #[ORM\Column(length: 255)]
        private string $title,
        #[ORM\Column(length: 255)]
        private string $artist,
        #[ORM\Column(length: 30)]
        private string $license,
        #[ORM\Column(length: 10, nullable: true)]
        private ?string $licenseVersion,
        #[ORM\Column(length: 50)]
        private string $provider,
        /** Durée en secondes, annoncée par Openverse. */
        #[ORM\Column]
        private int $duration,
        #[ORM\Column(type: Types::JSON)]
        private array $genres = [],
        #[ORM\Column(options: ['default' => false])]
        private bool $hasThumbnail = false,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->openverseId = strtolower($openverseId);
        $this->title = mb_substr(trim($title), 0, 255);
        $this->artist = mb_substr(trim($artist), 0, 255);
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getOpenverseId(): string
    {
        return $this->openverseId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getArtist(): string
    {
        return $this->artist;
    }

    public function getLicense(): string
    {
        return $this->license;
    }

    public function getLicenseVersion(): ?string
    {
        return $this->licenseVersion;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getDuration(): int
    {
        return $this->duration;
    }

    /**
     * @return list<string>
     */
    public function getGenres(): array
    {
        return array_values(array_filter($this->genres, \is_string(...)));
    }

    public function hasThumbnail(): bool
    {
        return $this->hasThumbnail;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
