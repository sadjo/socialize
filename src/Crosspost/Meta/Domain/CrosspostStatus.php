<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Domain;

enum CrosspostStatus: string
{
    /** Demandé, pas encore envoyé à Instagram. */
    case Pending = 'pending';
    /** Médias envoyés, Instagram les prépare. */
    case Processing = 'processing';
    case Published = 'published';
    case Failed = 'failed';
}
