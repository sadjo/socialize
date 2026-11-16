<?php

declare(strict_types=1);

namespace App\Web\Security;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Account\AccountUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Compte du visiteur connecté : le sien sur l'instance, ou son compte Mastodon s'il s'est connecté avec.
 */
final readonly class CurrentAccount
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function get(): ?Account
    {
        $user = $this->security->getUser();

        return $user instanceof AccountUser ? $user->getAccount() : null;
    }

    public function require(): Account
    {
        return $this->get() ?? throw new AccessDeniedException('Connectez-vous pour continuer.');
    }
}
