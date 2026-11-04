<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

use App\Core\Domain\Social\Follow;

/**
 * Un compte local vient de suivre un compte.
 */
final readonly class AccountFollowed
{
    public function __construct(
        public Follow $follow,
    ) {
    }
}
