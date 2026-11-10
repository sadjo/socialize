<?php

declare(strict_types=1);

namespace App\Core\Domain\Music;

use App\Core\Infrastructure\Doctrine\MusicTrackRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Morceau sous licence libre trouvé sur Openverse, copié sur l'instance. Un morceau sert à
 * toutes les publications et stories qui l'ont choisi ; l'attribution (titre, artiste, licence,
 * page d'origine) est affichée partout où il est joué.
 */
#[ORM\Entity(repositoryClass: MusicTrackRepository::class)]
#[ORM\Table(name: 'music_tracks')]
class MusicTrack
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        /** Identifiant du morceau chez Openverse. */
        #[ORM\Column(length: 64, unique: true)]
        private string $openverseId,
        #[ORM\Column(length: 255)]
        private string $title,
        #[ORM\Column(length: 255)]
        private string $artist,
        /** Licence Creative Commons, par exemple « by-sa » ou « cc0 ». */
        #[ORM\Column(length: 30)]
        private string $license,
        #[ORM\Column(length: 10, nullable: true)]
        private ?string $licenseVersion,
        #[ORM\Column(length: 500, nullable: true)]
        private ?string $licenseUrl,
        /** Page du morceau chez son fournisseur (Jamendo, Freesound…). */
        #[ORM\Column(length: 500, nullable: true)]
        private ?string $sourceUrl,
        #[ORM\Column(length: 50)]
        private string $provider,
        /** Durée en secondes. */
        #[ORM\Column]
        private int $duration,
        /** Copie AAC dans le stockage public. */
        #[ORM\Column(length: 255)]
        private string $path,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->title = mb_substr(trim($title), 0, 255) ?: 'Sans titre';
        $this->artist = mb_substr(trim($artist), 0, 255) ?: 'Artiste inconnu';
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->id->toBase58();
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

    /**
     * Nom lisible de la licence, par exemple « CC BY-SA 4.0 » ou « CC0 1.0 ».
     */
    public function getLicenseLabel(): string
    {
        return match (strtolower($this->license)) {
            'pdm' => 'Domaine public',
            'cc0' => trim('CC0 '.($this->licenseVersion ?? '')),
            default => trim('CC '.strtoupper($this->license).' '.($this->licenseVersion ?? '')),
        };
    }

    public function getLicenseVersion(): ?string
    {
        return $this->licenseVersion;
    }

    public function getLicenseUrl(): ?string
    {
        return $this->licenseUrl;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getDuration(): int
    {
        return $this->duration;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Point de départ valable pour un extrait : dans le morceau, en laissant si possible un extrait complet.
     */
    public function clampStart(int $start): int
    {
        return max(0, min($start, $this->duration - MusicChoice::EXCERPT));
    }
}
