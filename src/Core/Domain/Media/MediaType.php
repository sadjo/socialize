<?php

declare(strict_types=1);

namespace App\Core\Domain\Media;

enum MediaType: string
{
    case Image = 'image';
    case Video = 'video';
}
