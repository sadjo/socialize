<?php

declare(strict_types=1);

namespace App\Web\Command;

use App\Core\Application\Account\RegisterAccount;
use App\Core\Application\Account\RegistrationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crée un compte depuis la console, par exemple l'administrateur d'une instance
 * dont les inscriptions sont fermées.
 */
#[AsCommand(name: 'app:user:create', description: 'Crée un compte (et le rend administrateur avec --admin)')]
final readonly class CreateUserCommand
{
    public function __construct(
        private RegisterAccount $register,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Adresse e-mail')] string $email,
        #[Argument(description: 'Nom d’utilisateur (adresse dans le fédivers)')] string $username,
        #[Option(description: 'Donner les droits d’administration')] bool $admin = false,
        #[Option(description: 'Autoriser à publier (toujours permis à un administrateur)')] bool $publisher = false,
        #[Option(description: 'Nom affiché')] ?string $name = null,
        #[Option(description: 'Lire le mot de passe sur l’entrée standard (echo -n … | … --password-stdin)')] bool $passwordStdin = false,
    ): int {
        $password = PasswordPrompt::read($io, $passwordStdin);

        try {
            $user = $this->register->register($email, $username, $password, $name);
        } catch (RegistrationException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($admin && !$user->isAdmin()) {
            $user->promoteToAdmin();
        }
        if ($publisher) {
            $user->grantPublishing();
        }
        $this->em->flush();

        $io->success(\sprintf('Compte @%s créé%s.', $user->getAccount()->getUsername(), $user->isAdmin() ? ' avec les droits d’administration' : ''));

        return Command::SUCCESS;
    }
}
