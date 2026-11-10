<?php

declare(strict_types=1);

namespace App\Web\Controller\Payload;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Recherche de musique libre (sélecteur de musique des écrans de création).
 */
final readonly class MusicSearch
{
    public function __construct(
        #[Assert\Length(max: 100)]
        public string $q = '',
        #[Assert\Range(min: 1, max: 50)]
        public int $page = 1,
    ) {
    }
}
