<?php

declare(strict_types=1);

namespace App\Core\Domain\Account;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Personne connectée, quelle que soit sa façon de se connecter : compte de l'instance (User) ou compte d'un autre
 * serveur piloté depuis Socialize (connexion Mastodon).
 */
interface AccountUser extends UserInterface
{
    public function getAccount(): Account;

    public function isAdmin(): bool;
}
