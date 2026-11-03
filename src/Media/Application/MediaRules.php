<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Core\Domain\Media\MediaType;

/**
 * Formats acceptés à l'envoi.
 */
final class MediaRules
{
    public const array IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif', 'image/avif'];
    public const array VIDEO_MIME_TYPES = ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska'];
    public const string MAX_IMAGE_SIZE = '50M';
    public const string MAX_VIDEO_SIZE = '500M';

    /**
     * @return list<string>
     */
    public static function acceptedMimeTypes(): array
    {
        return [...self::IMAGE_MIME_TYPES, ...self::VIDEO_MIME_TYPES];
    }

    public static function typeOf(string $mimeType): ?MediaType
    {
        return match (true) {
            \in_array($mimeType, self::IMAGE_MIME_TYPES, true) => MediaType::Image,
            \in_array($mimeType, self::VIDEO_MIME_TYPES, true) => MediaType::Video,
            default => null,
        };
    }
}
