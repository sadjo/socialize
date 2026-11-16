<?php

declare(strict_types=1);

namespace App\Integration\Immich\Application;

/**
 * Photo ou vidéo de la photothèque Immich, telle que la décrit son API.
 */
final readonly class ImmichAsset
{
    /**
     * @param array<string, string> $exif        métadonnées gardées dans l'original publié
     * @param ?string               $liveVideoId photo live (iPhone, Android) : sa courte vidéo, un média caché d'Immich
     * @param bool                  $hidden      média caché de la photothèque (la vidéo d'une photo live)
     */
    public function __construct(
        public string $id,
        public bool $video,
        public string $fileName,
        public ?string $mimeType,
        public \DateTimeImmutable $takenAt,
        public ?float $duration,
        public ?int $size,
        public ?string $description,
        public ?string $place,
        public array $exif,
        public ?string $liveVideoId = null,
        public bool $hidden = false,
    ) {
    }

    /**
     * @param array<mixed> $data AssetResponseDto
     */
    public static function fromApi(array $data): self
    {
        $exif = \is_array($data['exifInfo'] ?? null) ? $data['exifInfo'] : [];
        $string = static fn (array $d, string $k): ?string => \is_string($d[$k] ?? null) && '' !== trim($d[$k]) ? trim($d[$k]) : null;

        $kept = [];
        foreach (['make', 'model', 'lensModel', 'fNumber', 'focalLength', 'iso', 'exposureTime', 'dateTimeOriginal', 'timeZone', 'city', 'state', 'country', 'description'] as $key) {
            if (\is_scalar($exif[$key] ?? null) && '' !== (string) $exif[$key]) {
                $kept['immich-'.$key] = (string) $exif[$key];
            }
        }
        foreach (['latitude', 'longitude'] as $key) {
            if (is_numeric($exif[$key] ?? null)) {
                $kept['gps-'.$key] = (string) $exif[$key];
            }
        }

        $taken = $string($data, 'localDateTime') ?? $string($exif, 'dateTimeOriginal') ?? $string($data, 'fileCreatedAt') ?? 'now';
        try {
            $takenAt = new \DateTimeImmutable($taken);
        } catch (\Exception) {
            $takenAt = new \DateTimeImmutable();
        }
        $place = implode(', ', array_filter([$string($exif, 'city'), $string($exif, 'country')]));

        return new self(
            id: \is_string($data['id'] ?? null) ? $data['id'] : '',
            video: 'VIDEO' === ($data['type'] ?? null),
            fileName: $string($data, 'originalFileName') ?? 'immich',
            mimeType: $string($data, 'originalMimeType'),
            takenAt: $takenAt,
            // Immich donne la durée en millisecondes.
            duration: is_numeric($data['duration'] ?? null) && (float) $data['duration'] > 0 ? (float) $data['duration'] / 1000 : null,
            size: is_numeric($exif['fileSizeInByte'] ?? null) ? (int) $exif['fileSizeInByte'] : null,
            description: $string($exif, 'description'),
            place: '' !== $place ? $place : null,
            exif: $kept,
            liveVideoId: \is_string($data['livePhotoVideoId'] ?? null) && '' !== $data['livePhotoVideoId'] ? $data['livePhotoVideoId'] : null,
            hidden: 'hidden' === ($data['visibility'] ?? null) || false === ($data['isVisible'] ?? null),
        );
    }

    public function live(): bool
    {
        return null !== $this->liveVideoId;
    }

    public function durationLabel(): ?string
    {
        if (null === $this->duration) {
            return null;
        }
        $seconds = (int) round($this->duration);

        return \sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
