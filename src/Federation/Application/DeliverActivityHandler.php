<?php

declare(strict_types=1);

namespace App\Federation\Application;

use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Federation\Infrastructure\FederationClient;
use App\Federation\Infrastructure\FederationException;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * Livre une activité. Les erreurs temporaires (réseau, 429, 5xx) sont réessayées par Messenger,
 * avec un délai croissant ; un refus définitif (4xx) est seulement journalisé.
 */
#[AsMessageHandler]
#[WithMonologChannel('federation')]
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

        $activity = json_decode($message->json, true);
        $context = [
            'inbox' => $message->inbox,
            'type' => \is_array($activity) ? ($activity['type'] ?? null) : null,
            'id' => \is_array($activity) ? ($activity['id'] ?? null) : null,
        ];

        try {
            $status = $this->client->post($message->inbox, $message->json, $this->signer->credentialsOf($account));
        } catch (FederationException $e) {
            $this->logger->warning('Envoi impossible, nouvel essai plus tard', $context + ['reason' => $e->getMessage()]);
            throw new RecoverableMessageHandlingException($e->getMessage(), $e->getCode(), previous: $e);
        }

        if (429 === $status || $status >= 500) {
            $this->logger->warning('Envoi refusé temporairement, nouvel essai plus tard', $context + ['status' => $status]);
            throw new RecoverableMessageHandlingException(\sprintf('%s a répondu %d, nouvel essai plus tard.', $message->inbox, $status));
        }
        if ($status >= 400) {
            $this->logger->warning('Activité refusée par une instance distante', $context + ['status' => $status]);

            return;
        }
        $this->logger->info('Activité livrée', $context + ['status' => $status]);
    }
}
