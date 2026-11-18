<?php

declare(strict_types=1);

namespace App\Web\Help;

/**
 * Chapitre du guide d'utilisation (docs/guide/guide.yaml).
 */
final readonly class HelpChapter
{
    public function __construct(
        public string $slug,
        public string $title,
        public bool $adminOnly,
    ) {
    }
}
