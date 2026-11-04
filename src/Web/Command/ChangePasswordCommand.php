<?php

declare(strict_types=1);

namespace App\Web\Command;

use App\Core\Infrastructure\Doctrine\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Change le mot de passe d'un compte depuis la console.
 */
#[AsCommand(name: 'app:user:password', description: 'Change le mot de passe d’un compte (adresse e-mail ou nom d’utilisateur)')]
final readonly class ChangePasswordCommand
{
    public function __construct(
        private UserRepository $users,
        private UserPasswordHasherInterface $hasher,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Adresse e-mail ou nom d’utilisateur')] string $identifier,
        #[Option(description: 'Lire le mot de passe sur l’entrée standard (echo -n … | … --password-stdin)')] bool $passwordStdin = false,
    ): int {
        $user = $this->users->loadUserByIdentifier($identifier);
        if (!$user instanceof \App\Core\Domain\Account\User) {
            $io->error(\sprintf('Aucun compte ne correspond à « %s ».', $identifier));

            return Command::FAILURE;
        }

        $user->changePassword($this->hasher->hashPassword($user, PasswordPrompt::read($io, $passwordStdin)));
        $this->em->flush();

        $io->success(\sprintf('Mot de passe de @%s changé.', $user->getAccount()->getUsername()));

        return Command::SUCCESS;
    }
}
