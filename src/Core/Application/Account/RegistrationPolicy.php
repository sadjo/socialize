<?php

declare(strict_types=1);

namespace App\Core\Application\Account;

use App\Core\Infrastructure\Doctrine\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Qui peut créer un compte. Le tout premier compte devient l'administrateur de l'instance :
 * il peut s'inscrire depuis le site, sauf quand les inscriptions sont fermées (closed).
 * Dans ce cas, l'administrateur se crée avec la commande app:user:create.
 */
final readonly class RegistrationPolicy
{
    private RegistrationMode $mode;

    public function __construct(
        private UserRepository $users,
        #[Autowire(env: 'REGISTRATION')]
        string $mode,
    ) {
        $this->mode = RegistrationMode::tryFrom($mode) ?? RegistrationMode::Invite;
    }

    public function mode(): RegistrationMode
    {
        return $this->mode;
    }

    public function isFirstAccount(): bool
    {
        return !$this->users->hasAny();
    }

    public function isOpen(): bool
    {
        return match ($this->mode) {
            RegistrationMode::Open => true,
            RegistrationMode::Invite => $this->isFirstAccount(),
            RegistrationMode::Closed => false,
        };
    }
}
