<?php

declare(strict_types=1);

namespace App\Import\Instagram\Domain;

enum ItemStatus: string
{
    case Pending = 'pending';
    case Imported = 'imported';
    case Skipped = 'skipped';
    /** Déjà importé par un import précédent. */
    case Duplicate = 'duplicate';
    case Failed = 'failed';
}
