<?php

declare(strict_types=1);

namespace App\Media\Application\Library;

use Symfony\Component\HttpFoundation\File\File;

/**
 * Original d'une photothèque externe, téléchargé dans un fichier temporaire pour être publié.
 */
final readonly class LibraryMedia
{
    /**
     * @param string                $reference identifiant du média dans sa photothèque (jeton sans préfixe)
     * @param array<string, string> $exif      métadonnées connues de la photothèque, gardées avec l'original publié
     */
    public function __construct(
        public string $reference,
        public File $file,
        public array $exif = [],
    ) {
    }
}
