<?php

declare(strict_types=1);

namespace App\Import\Instagram\Domain;

enum ItemKind: string
{
    case Post = 'post';
    case ArchivedPost = 'archived_post';
    case Story = 'story';

    public function label(): string
    {
        return match ($this) {
            self::Post => 'Publication',
            self::ArchivedPost => 'Archivée sur Instagram',
            self::Story => 'Story',
        };
    }

    public function isPost(): bool
    {
        return self::Story !== $this;
    }
}
