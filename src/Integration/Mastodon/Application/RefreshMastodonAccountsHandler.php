<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

use App\Federation\Application\BackfillAccount;
use App\Integration\Mastodon\Infrastructure\Doctrine\MastodonUserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class RefreshMastodonAccountsHandler
{
    public function __construct(
        private MastodonUserRepository $users,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(RefreshMastodonAccounts $message): void
    {
        foreach ($this->users->connected() as $user) {
            $this->bus->dispatch(new BackfillAccount($user->getAccount()->getId()->toRfc4122()));
        }
    }
}
