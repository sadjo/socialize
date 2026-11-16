<?php

declare(strict_types=1);

namespace App\Media\Application\Library;

use App\Core\Domain\Account\Account;
use App\Media\Application\MediaProcessingException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Photothèque externe d'un compte (Immich, Synology Photos…) dont les médias se publient comme ceux de l'appareil.
 * Dans l'ordre envoyé par l'écran de création, ses médias sont les jetons « <préfixe>-<référence> ».
 */
#[AutoconfigureTag(self::TAG)]
interface LibrarySource
{
    public const string TAG = 'app.library_source';

    /**
     * Suffixe de la référence d'une photo live publiée en version live (sa courte vidéo) : « <référence>-live ».
     * Sans lui, c'est la photo fixe qui est publiée.
     */
    public const string LIVE = '-live';

    /**
     * Préfixe de ses jetons (une lettre : « i » pour Immich, « s » pour Synology Photos).
     */
    public function prefix(): string;

    /**
     * Télécharge l'original dans un fichier temporaire (nom commençant par « socialize- », supprimé par l'appelant).
     *
     * @throws MediaProcessingException référence invalide, photothèque non connectée ou injoignable, média refusé
     */
    public function fetch(Account $author, string $reference): LibraryMedia;

    /**
     * Après la publication : suite propre à la photothèque (ex. ajout à un album).
     *
     * @param list<string> $references médias publiés
     */
    public function published(Account $author, array $references): void;
}
