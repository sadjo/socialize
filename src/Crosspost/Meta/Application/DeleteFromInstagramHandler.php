<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Application;

use App\Core\Domain\Account\Account;
use App\Crosspost\Meta\Domain\InstagramConnection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * Supprime sur Instagram la copie d'une publication supprimée ici (connexion unifiée seulement). Une erreur
 * temporaire est réessayée ; un refus est consigné (la copie se supprime alors dans l'application Instagram).
 */
#[AsMessageHandler]
final readonly class DeleteFromInstagramHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private InstagramConnections $connections,
        private InstagramPublishing $publishing,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeleteFromInstagram $message): void
    {
        $account = $this->em->find(Account::class, Uuid::fromString($message->accountId));
        $connection = $this->connections->usable($account);
        if (!$connection instanceof InstagramConnection) {
            $this->logger->warning('Publication Instagram non supprimée : Instagram non connecté', ['media' => $message->mediaId]);

            return;
        }
        try {
            $this->publishing->deleteFromInstagram($connection, $message->mediaId);
        } catch (InstagramException $e) {
            if ($e->temporary) {
                throw new RecoverableMessageHandlingException($e->getMessage(), 0, $e);
            }
            $this->logger->warning('Publication Instagram non supprimée', ['media' => $message->mediaId, 'raison' => $e->getMessage()]);
        }
    }
}
