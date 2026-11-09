<?php

declare(strict_types=1);

namespace App\Import\Instagram\Domain;

use App\Core\Domain\Post\Visibility;

/**
 * Ce que devient une publication importée : sa visibilité, son archivage, ou rien.
 */
enum Placement: string
{
    case PublicQuiet = 'public_quiet';
    case Public = 'public';
    case Followers = 'followers';
    case Private = 'private';
    case Archive = 'archive';
    case Skip = 'skip';

    /**
     * Choix possibles pour tout l'import (les autres se font publication par publication).
     *
     * @return list<self>
     */
    public static function forWholeImport(): array
    {
        return [self::PublicQuiet, self::Public, self::Followers, self::Private];
    }

    public function label(): string
    {
        return match ($this) {
            self::PublicQuiet => 'Public, sans diffusion fédérée',
            self::Public => 'Public, envoyé à mes abonnés du fédivers',
            self::Followers => 'Abonnés seulement',
            self::Private => 'Privé (moi seul)',
            self::Archive => 'Archiver',
            self::Skip => 'Ne pas importer',
        };
    }

    /**
     * Visibilité de la publication ; une publication archivée garde celle de l'import pour le jour où elle en sort.
     */
    public function visibility(self $whole): Visibility
    {
        return match ($this) {
            self::PublicQuiet, self::Public => Visibility::Public,
            self::Followers => Visibility::Followers,
            self::Private => Visibility::Private,
            self::Archive, self::Skip => $whole->visibility($whole),
        };
    }

    /**
     * Seul « Public, envoyé à mes abonnés » annonce la publication au fédivers à l'import.
     */
    public function announces(): bool
    {
        return self::Public === $this;
    }
}
