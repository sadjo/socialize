<?php

declare(strict_types=1);

namespace App\Core\Domain\Media;

enum MediaStatus: string
{
    /** Original enregistré, copies en cours de fabrication (vidéos). */
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
