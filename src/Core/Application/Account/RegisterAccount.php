<?php

declare(strict_types=1);

namespace App\Core\Application\Account;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Account\Invitation;
use App\Core\Domain\Account\User;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Core\Infrastructure\Doctrine\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class RegisterAccount
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private AccountRepository $accounts,
        private UserPasswordHasherInterface $hasher,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Crée un compte ; avec une invitation, celle-ci est marquée utilisée dans le même enregistrement.
     *
     * @throws RegistrationException
     */
    public function register(string $email, string $username, string $plainPassword, ?string $displayName = null, ?Invitation $invitation = null): User
    {
        if ($invitation instanceof Invitation) {
            if (!$invitation->isUsable($this->clock->now())) {
                throw new RegistrationException('Cette invitation n’est plus valable.');
            }
            if (null !== $invitation->getEmail() && $invitation->getEmail() !== mb_strtolower(trim($email))) {
                throw new RegistrationException('Cette invitation est réservée à une autre adresse e-mail.');
            }
        }
        $username = mb_strtolower(trim($username));
        if (!preg_match('/^'.Account::USERNAME_PATTERN.'$/', $username)) {
            throw new RegistrationException('Le nom d’utilisateur ne peut contenir que des lettres minuscules, des chiffres, des points et des tirets bas (30 caractères au plus).');
        }
        if ($this->accounts->isLocalUsernameTaken($username)) {
            throw new RegistrationException('Ce nom d’utilisateur est déjà pris.');
        }
        if ($this->users->findOneByEmail($email) instanceof User) {
            throw new RegistrationException('Un compte existe déjà avec cette adresse e-mail.');
        }

        $isFirst = !$this->users->hasAny();
        $account = new Account($username, $displayName ?? $username);
        $user = new User($email, $account);
        $user->changePassword($this->hasher->hashPassword($user, $plainPassword));
        if ($isFirst) {
            $user->promoteToAdmin();
        }
        $invitation?->use($account, $this->clock->now());

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
