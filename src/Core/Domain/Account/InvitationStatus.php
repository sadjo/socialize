<?php

declare(strict_types=1);

namespace App\Core\Domain\Account;

enum InvitationStatus: string
{
    case Pending = 'pending';
    case Used = 'used';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Used => 'Utilisée',
            self::Expired => 'Expirée',
            self::Revoked => 'Révoquée',
        };
    }
}
