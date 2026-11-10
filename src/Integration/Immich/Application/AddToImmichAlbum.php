<?php

declare(strict_types=1);

namespace App\Integration\Immich\Application;

/**
 * Ajouter des médias Immich publiés à l'album « Publié sur Socialize » du compte.
 */
final readonly class AddToImmichAlbum
{
    /**
     * @param list<string> $assetIds
     */
    public function __construct(
        public string $connectionId,
        public array $assetIds,
    ) {
    }
}
