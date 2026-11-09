<?php

declare(strict_types=1);

namespace App\Web\Controller\Payload;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ArchivePart
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[Assert\Regex('/\.zip$/i', message: 'Choisissez l’archive .zip téléchargée depuis Instagram.')]
        public string $name,
        #[Assert\Positive]
        public int $size,
    ) {
    }
}
