<?php

declare(strict_types=1);

namespace App\Federation\Application;

use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Federation\Infrastructure\FederationClient;
use App\Federation\Infrastructure\FederationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * Livre une activité. Les erreurs temporaires (réseau, 429, 5xx) sont réessayées par Messenger,
 * avec un délai croissant ; un refus définitif (4xx) est seulement journalisé.
 */
#[AsMessageHandler]
final readonly class DeliverActivityHandler
{
    public function __construct(
        private FederationClient $client,
        private AccountRepository $accounts,
        private Signer $signer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeliverActivity $message): void
    {
        $account = $this->accounts->find(Uuid::fromString($message->signerAccountId));
        if (null === $account) {
            return;
        }

        try {
            $status = $this->client->post($message->inbox, $message->json, $this->signer->credentialsOf($account));
        } catch (FederationException $e) {
            throw new RecoverableMessageHandlingException($e->getMessage(), $e->getCode(), previous: $e);
        }

        if (429 === $status || $status >= 500) {
            throw new RecoverableMessageHandlingException(\sprintf('%s a répondu %d, nouvel essai plus tard.', $message->inbox, $status));
        }
        if ($status >= 400) {
            $this->logger->notice('Activité refusée par une instance distante', ['inbox' => $message->inbox, 'status' => $status]);
        }
    }
}
