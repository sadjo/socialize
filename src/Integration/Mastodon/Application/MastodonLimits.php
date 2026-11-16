<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

/**
 * Limites d'un serveur Mastodon pour une publication (API /api/v2/instance), avec les valeurs par défaut de
 * Mastodon quand le serveur ne les donne pas.
 */
final readonly class MastodonLimits
{
    /**
     * @param positive-int $maxCharacters
     * @param positive-int $maxMedia
     * @param positive-int $imageSize     octets
     * @param positive-int $videoSize     octets
     * @param list<string> $mimeTypes     types de fichiers acceptés (vide : non précisé)
     */
    public function __construct(
        public int $maxCharacters = 500,
        public int $maxMedia = 4,
        public int $imageSize = 16 * 1024 * 1024,
        public int $videoSize = 99 * 1024 * 1024,
        public array $mimeTypes = [],
    ) {
    }

    /**
     * @param array<mixed> $instance réponse de /api/v2/instance
     */
    public static function fromApi(array $instance): self
    {
        $configuration = \is_array($instance['configuration'] ?? null) ? $instance['configuration'] : [];
        $statuses = \is_array($configuration['statuses'] ?? null) ? $configuration['statuses'] : [];
        $media = \is_array($configuration['media_attachments'] ?? null) ? $configuration['media_attachments'] : [];
        $types = \is_array($media['supported_mime_types'] ?? null) ? array_values(array_filter($media['supported_mime_types'], \is_string(...))) : [];

        return new self(
            maxCharacters: self::positive($statuses, 'max_characters', 500),
            maxMedia: self::positive($statuses, 'max_media_attachments', 4),
            imageSize: self::positive($media, 'image_size_limit', 16 * 1024 * 1024),
            videoSize: self::positive($media, 'video_size_limit', 99 * 1024 * 1024),
            mimeTypes: $types,
        );
    }

    /**
     * @param array<mixed> $data
     * @param positive-int $default
     *
     * @return positive-int
     */
    private static function positive(array $data, string $key, int $default): int
    {
        $value = is_numeric($data[$key] ?? null) ? (int) $data[$key] : 0;

        return $value > 0 ? $value : $default;
    }

    public function accepts(string $mimeType): bool
    {
        return [] === $this->mimeTypes || \in_array($mimeType, $this->mimeTypes, true);
    }

    public function maxSizeFor(string $mimeType): int
    {
        return str_starts_with($mimeType, 'video/') ? $this->videoSize : $this->imageSize;
    }
}
