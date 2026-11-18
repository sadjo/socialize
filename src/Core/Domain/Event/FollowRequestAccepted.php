<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

use App\Core\Domain\Social\Follow;

/**
 * Un compte local a accepté une demande d'abonnement (l'abonnement est désormais effectif).
 */
final readonly class FollowRequestAccepted
{
    public function __construct(
        public Follow $follow,
    ) {
    }
}
