<?php

declare(strict_types=1);

namespace App\Core\Domain\Post;

enum Visibility: string
{
    /** Visible par tous, dans les fils publics et le fédivers. */
    case Public = 'public';
    /** Visible par tous avec le lien, absent des fils publics. */
    case Unlisted = 'unlisted';
    /** Réservé aux abonnés. */
    case Followers = 'followers';
    /** Réservé aux abonnés membres d'un ou de plusieurs groupes de l'autrice ou de l'auteur (Famille, Amis…). */
    case Groups = 'groups';
    /** Réservé à l'autrice ou à l'auteur (et plus tard aux comptes mentionnés). */
    case Private = 'private';

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
            self::Public => 'Visible par tout le monde et dans les fils publics',
            self::Unlisted => 'Visible avec le lien, absent des fils publics',
            self::Followers => 'Visible uniquement par vos abonnés',
            self::Groups => 'Visible par les abonnés de vos groupes',
            self::Private => 'Visible uniquement par vous',
        };
    }
}
