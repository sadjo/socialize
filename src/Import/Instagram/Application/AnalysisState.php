<?php

declare(strict_types=1);

namespace App\Import\Instagram\Application;

use App\Import\Instagram\Domain\InstagramImport;
use App\Import\Instagram\Infrastructure\ArchiveReader;

/**
 * Compteurs d'une analyse en cours (voir ArchiveAnalyzer).
 */
final class AnalysisState
{
    /** @var array{posts: int, archived: int, stories: int, media: int, duplicates: int} */
    public array $counts = ['posts' => 0, 'archived' => 0, 'stories' => 0, 'media' => 0, 'duplicates' => 0];

    /** @var array<string, true> empreintes déjà vues dans cette archive */
    public array $seen = [];

    public int $missing = 0;
    public int $repaired = 0;

    public function __construct(
        public readonly InstagramImport $import,
        public readonly ArchiveReader $archive,
    ) {
    }
}
