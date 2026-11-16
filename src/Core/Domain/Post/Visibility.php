<?php

declare(strict_types=1);

namespace App\Core\Domain\Post;

enum Visibility: string
{
    /** Visible par tous, dans les fils publics et le fédivers. */
    case Public = 'public';
    /** Visible par tous avec le lien, absent des fils publics ; reste sur l'instance. */
    case Unlisted = 'unlisted';
    /** Réservé aux abonnés. */
    case Followers = 'followers';
    /** Réservé aux abonnés membres d'un ou de plusieurs groupes de l'autrice ou de l'auteur (Famille, Amis…) ; reste sur
     * l'instance. */
    case Groups = 'groups';
    /** Réservé à l'autrice ou à l'auteur (et plus tard aux comptes mentionnés). */
    case Private = 'private';

    /**
     * Part dans le fédivers ? Seules les publications publiques et réservées aux abonnés y sont envoyées ; les autres
     * restent sur l'instance (les membres d'un groupe connectés avec Mastodon les y voient).
     */
    public function isFederated(): bool
    {
        return self::Public === $this || self::Followers === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Unlisted => 'Non répertorié',
            self::Followers => 'Abonnés',
            self::Groups => 'Groupes',
            self::Private => 'Privé',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Public => 'Visible par tout le monde, dans les fils publics et le fédivers',
            self::Unlisted => 'Visible avec le lien, sur cette instance seulement',
            self::Followers => 'Visible uniquement par vos abonnés, ici et dans le fédivers',
            self::Groups => 'Visible par les membres de vos groupes, sur cette instance seulement',
            self::Private => 'Visible uniquement par vous',
        };
    }
}
