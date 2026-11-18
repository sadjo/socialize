<?php

declare(strict_types=1);

namespace App\Core\Domain\Social;

enum FollowState: string
{
    /** Demande en attente : à valider par le compte suivi, ou accord d'une instance distante. */
    case Pending = 'pending';
    case Accepted = 'accepted';
}
