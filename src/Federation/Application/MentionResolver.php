<?php

declare(strict_types=1);

namespace App\Federation\Application;

use App\Core\Application\Post\Mentions;
use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use Psr\Log\LoggerInterface;

/**
 * Comptes du fédivers mentionnés mais encore inconnus de l'instance : cherchés par WebFinger avant l'envoi d'une
 * publication, pour qu'elle les mentionne vraiment. Un compte introuvable reste une simple adresse dans le texte.
 */
final readonly class MentionResolver
{
    /** Recherches réseau au plus par publication. */
    private const int MAX_LOOKUPS = 5;

    public function __construct(
        private Mentions $mentions,
        private AccountRepository $accounts,
        private RemoteAccounts $remote,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(string $text): void
    {
        $lookups = 0;
        foreach ($this->mentions->handles($text) as $handle) {
            if (null === $handle['domain'] || $this->accounts->findOneBy($handle) instanceof Account) {
                continue;
            }
            if (++$lookups > self::MAX_LOOKUPS) {
                return;
            }
            try {
                $this->remote->byHandle($handle['username'], $handle['domain']);
            } catch (\Throwable $e) {
                $this->logger->info('Mention introuvable : @{handle}.', ['handle' => $handle['username'].'@'.$handle['domain'], 'exception' => $e]);
            }
        }
    }
}
