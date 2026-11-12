<?php

declare(strict_types=1);

namespace App\Core\Domain\Account;

/**
 * Droits d'un compte accordés par l'administration (un administrateur les a tous).
 * La valeur sert dans l'adresse de l'action d'administration.
 */
enum AccountRight: string
{
    /** Publications, stories et photothèque Immich. */
    case Publish = 'publication';
    /** Import d'une archive Instagram ; suppose aussi le droit de publier. */
    case InstagramImport = 'import-instagram';

    public function role(): string
    {
        return match ($this) {
            self::Publish => User::ROLE_PUBLISHER,
            self::InstagramImport => User::ROLE_INSTAGRAM_IMPORTER,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Publish => 'Publication',
            self::InstagramImport => 'Import Instagram',
        };
    }

    /**
     * Message après l'octroi ou le retrait, pour le compte @$username.
     */
    public function changedMessage(string $username, bool $granted): string
    {
        return match ($this) {
            self::Publish => $granted
                ? \sprintf('@%s peut désormais publier.', $username)
                : \sprintf('@%s ne peut plus publier. Ses publications existantes restent en ligne.', $username),
            self::InstagramImport => $granted
                ? \sprintf('@%s peut désormais importer son archive Instagram.', $username)
                : \sprintf('@%s ne peut plus importer d’archive Instagram. Ses publications déjà importées restent en ligne.', $username),
        };
    }
}
