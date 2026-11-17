<?php

declare(strict_types=1);

namespace App\Media\Application;

/**
 * Les deux images d'un profil, avec leurs dimensions publiées.
 */
enum ProfileImage: string
{
    case Avatar = 'avatar';
    case Cover = 'cover';

    /** Bandeau de couverture 4:1. */
    public const int COVER_WIDTH = 1600;
    public const int COVER_HEIGHT = 400;
    public const int AVATAR_SIZE = 400;

    public function width(): int
    {
        return self::Avatar === $this ? self::AVATAR_SIZE : self::COVER_WIDTH;
    }

    public function height(): int
    {
        return self::Avatar === $this ? self::AVATAR_SIZE : self::COVER_HEIGHT;
    }

    public function directory(): string
    {
        return self::Avatar === $this ? 'avatars' : 'covers';
    }
}
