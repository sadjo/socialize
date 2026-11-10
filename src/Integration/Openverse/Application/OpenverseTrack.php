<?php

declare(strict_types=1);

namespace App\Integration\Openverse\Application;

use App\Core\Domain\Music\MusicTrack;

/**
 * Morceau trouvé sur Openverse (résultat de recherche ou fiche détaillée).
 */
final readonly class OpenverseTrack
{
    public function __construct(
        public string $id,
        public string $title,
        public string $artist,
        public string $license,
        public ?string $licenseVersion,
        public ?string $licenseUrl,
        public ?string $sourceUrl,
        public string $provider,
        /** Durée en secondes (0 si inconnue). */
        public int $duration,
        /** Adresse du fichier audio chez le fournisseur. */
        public ?string $fileUrl,
        /** @var list<string> */
        public array $genres = [],
        /** Vignette (pochette) fournie par Openverse, s'il y en a une. */
        public bool $hasThumbnail = false,
    ) {
    }

    /**
     * @param array<mixed> $data objet « audio » de l'API Openverse
     */
    public static function fromApi(array $data): self
    {
        $string = static fn (string $key): ?string => \is_string($data[$key] ?? null) && '' !== trim($data[$key]) ? trim($data[$key]) : null;

        return new self(
            id: $string('id') ?? throw new OpenverseException('Réponse d’Openverse sans identifiant de morceau.'),
            title: $string('title') ?? 'Sans titre',
            artist: $string('creator') ?? 'Artiste inconnu',
            license: $string('license') ?? 'inconnue',
            licenseVersion: $string('license_version'),
            licenseUrl: $string('license_url'),
            sourceUrl: $string('foreign_landing_url'),
            provider: $string('source') ?? $string('provider') ?? 'openverse',
            duration: is_numeric($data['duration'] ?? null) ? (int) round((float) $data['duration'] / 1000) : 0,
            fileUrl: $string('url'),
            genres: array_values(array_filter(\is_array($data['genres'] ?? null) ? $data['genres'] : [], \is_string(...))),
            hasThumbnail: null !== $string('thumbnail'),
        );
    }

    public function getLicenseLabel(): string
    {
        return MusicTrack::formatLicense($this->license, $this->licenseVersion);
    }
}
