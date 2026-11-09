<?php

declare(strict_types=1);

namespace App\Import\Instagram\Application;

final readonly class AnalyzeInstagramArchive
{
    public function __construct(
        public string $importId,
    ) {
    }
}
