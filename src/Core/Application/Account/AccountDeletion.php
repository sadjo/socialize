<?php

declare(strict_types=1);

namespace App\Core\Application\Account;

use App\Core\Domain\Account\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Suppression d'un compte de l'instance par l'administration : sa connexion disparaît aussitôt (la personne est
 * déconnectée partout), puis tout le reste en tâche de fond (DeleteAccountHandler).
 */
final readonly class AccountDeletion
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
    ) {
    }

    public function delete(User $user): void
    {
        $account = $user->getAccount();
        $this->em->remove($user);
        $this->em->flush();
        $this->bus->dispatch(new DeleteAccount($account->getId()->toRfc4122()));
    }
}
