<?php

declare(strict_types=1);

namespace App\Core\Application\Account;

enum RegistrationMode: string
{
    case Open = 'open';
    case Invite = 'invite';
    case Closed = 'closed';
}
