<?php

declare(strict_types=1);

namespace App\Import\Instagram\Application;

/**
 * Morceau refusé : il ne commence pas là où la partie s'est arrêtée (reprise après coupure).
 */
final class ChunkRejected extends \RuntimeException
{
    public function __construct(
        public readonly int $expectedOffset,
    ) {
        parent::__construct(\sprintf('Le morceau doit commencer à l’octet %d.', $expectedOffset));
    }
}
