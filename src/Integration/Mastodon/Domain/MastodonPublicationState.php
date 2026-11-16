<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Domain;

enum MastodonPublicationState: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
}
