<?php

declare(strict_types=1);

namespace App\Integration\SynologyPhotos\Application;

/**
 * Photo ou vidéo de Synology Photos, telle que la décrit son API (SYNO.Foto.Browse.Item).
 */
final readonly class SynologyPhotosItem
{
    /** Espace personnel du compte DSM. */
    public const string PERSONAL = 'p';
    /** Espace partagé du NAS (API SYNO.FotoTeam). */
    public const string TEAM = 't';

    /**
     * Versions converties d'une vidéo lisibles par un navigateur (H.264 en MP4), de la plus légère à la plus lourde :
     * l'aperçu prend la première disponible. « flv » n'est pas lisible.
     */
    public const array STREAM_QUALITIES = ['medium', 'low', 'high', 'orig_h264', 'orig_mp4', 'convert_mp4'];

    /**
     * @param self::PERSONAL|self::TEAM $space
     * @param ?string                   $cacheKey      clé de la vignette (demandée par l'API des vignettes)
     * @param array<string, string>     $exif          métadonnées gardées dans l'original publié
     * @param ?string                   $streamQuality version convertie lue dans l'aperçu (vidéo ; null : aucune)
     */
    public function __construct(
        public int $id,
        public string $space,
        public bool $video,
        public string $fileName,
        public \DateTimeImmutable $takenAt,
        public ?float $duration,
        public ?int $size,
        public ?string $cacheKey,
        public ?string $place,
        public array $exif,
        public ?string $streamQuality = null,
    ) {
    }

    /**
     * @param array<mixed>              $data  élément de la liste
     * @param self::PERSONAL|self::TEAM $space espace interrogé ; dans un album, l'espace partagé se reconnaît à son propriétaire (0)
     */
    public static function fromApi(array $data, string $space): self
    {
        $additional = \is_array($data['additional'] ?? null) ? $data['additional'] : [];
        $thumbnail = \is_array($additional['thumbnail'] ?? null) ? $additional['thumbnail'] : [];
        $video = \is_array($additional['video_meta'] ?? null) ? $additional['video_meta'] : [];
        $exif = \is_array($additional['exif'] ?? null) ? $additional['exif'] : [];
        $gps = \is_array($additional['gps'] ?? null) ? $additional['gps'] : [];
        $address = \is_array($additional['address'] ?? null) ? $additional['address'] : [];
        $converted = array_column(array_filter(\is_array($additional['video_convert'] ?? null) ? $additional['video_convert'] : [], \is_array(...)), 'quality');
        $string = static fn (array $d, string $k): ?string => \is_scalar($d[$k] ?? null) && '' !== trim((string) $d[$k]) ? trim((string) $d[$k]) : null;

        $kept = [];
        foreach (['camera', 'lens', 'aperture', 'focal_length', 'iso', 'exposure_time'] as $key) {
            if (null !== $string($exif, $key)) {
                $kept['synology-'.$key] = $string($exif, $key);
            }
        }
        foreach (['latitude', 'longitude'] as $key) {
            if (is_numeric($gps[$key] ?? null)) {
                $kept['gps-'.$key] = (string) $gps[$key];
            }
        }
        $place = implode(', ', array_filter([$string($address, 'city'), $string($address, 'country')]));
        if (self::PERSONAL === $space && 0 === ($data['owner_user_id'] ?? null)) {
            $space = self::TEAM;
        }

        return new self(
            id: is_numeric($data['id'] ?? null) ? (int) $data['id'] : 0,
            space: $space,
            video: 'video' === ($data['type'] ?? null),
            fileName: $string($data, 'filename') ?? 'synology',
            // Heure de prise de vue, donnée comme une heure locale exprimée en UTC : à afficher en UTC.
            takenAt: new \DateTimeImmutable('@'.(is_numeric($data['time'] ?? null) ? (int) $data['time'] : 0)),
            // Durée en millisecondes.
            duration: is_numeric($video['duration'] ?? null) && (float) $video['duration'] > 0 ? (float) $video['duration'] / 1000 : null,
            size: is_numeric($data['filesize'] ?? null) ? (int) $data['filesize'] : null,
            cacheKey: \is_string($thumbnail['cache_key'] ?? null) && preg_match('/^\d+_\d+$/', $thumbnail['cache_key']) ? $thumbnail['cache_key'] : null,
            place: '' !== $place ? $place : null,
            exif: $kept,
            streamQuality: array_find(self::STREAM_QUALITIES, static fn (string $quality): bool => \in_array($quality, $converted, true)),
        );
    }

    /**
     * Référence dans l'ordre des médias à publier : « <espace>-<id> » (jeton « s-<espace>-<id> »).
     */
    public function reference(): string
    {
        return $this->space.'-'.$this->id;
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
