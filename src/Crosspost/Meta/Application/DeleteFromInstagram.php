<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Application;

/**
 * Supprimer une publication Instagram (sa publication a été supprimée ici).
 */
final readonly class DeleteFromInstagram
{
    public function __construct(
        public string $accountId,
        public string $mediaId,
    ) {
    }
}
