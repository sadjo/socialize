<?php

declare(strict_types=1);

namespace App\Core\Application\Account;

use App\Core\Infrastructure\Doctrine\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Qui peut créer un compte. Le tout premier compte est toujours accepté :
 * il devient l'administrateur de l'instance.
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
        return RegistrationMode::Open === $this->mode || $this->isFirstAccount();
    }
}
