<?php

declare(strict_types=1);

namespace App\Core\Domain\Post;

enum PostSource: string
{
    case Local = 'local';
    case Federated = 'federated';
    case InstagramImport = 'instagram_import';
}
