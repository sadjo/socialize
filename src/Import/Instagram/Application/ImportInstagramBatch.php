<?php

declare(strict_types=1);

namespace App\Import\Instagram\Application;

/**
 * Importe le lot suivant d'éléments, puis se renvoie pour la suite (chaque message reste court).
 */
final readonly class ImportInstagramBatch
{
    public function __construct(
        public string $importId,
    ) {
    }
}
