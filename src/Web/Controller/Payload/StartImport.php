<?php

declare(strict_types=1);

namespace App\Web\Controller\Payload;

use App\Import\Instagram\Domain\InstagramImport;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Début d'un envoi : les fichiers de l'archive (une ou plusieurs parties).
 */
final readonly class StartImport
{
    public function __construct(
        /** @var list<ArchivePart> */
        #[Assert\Count(min: 1, max: InstagramImport::MAX_PARTS)]
        #[Assert\Valid]
        public array $parts = [],
    ) {
    }
}
