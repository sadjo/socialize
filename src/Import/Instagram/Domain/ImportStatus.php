<?php

declare(strict_types=1);

namespace App\Import\Instagram\Domain;

enum ImportStatus: string
{
    /** L'archive arrive, morceau par morceau. */
    case Uploading = 'uploading';
    case Analyzing = 'analyzing';
    /** Analyse terminée : rien n'est encore importé, les options restent à choisir. */
    case Ready = 'ready';
    case Importing = 'importing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return self::Completed !== $this && self::Failed !== $this;
    }
}
