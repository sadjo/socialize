<?php

declare(strict_types=1);

namespace App\Federation\Application;

use App\Core\Domain\Event\AccountDeleting;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use App\Federation\Infrastructure\FederationClient;
use App\Federation\Infrastructure\FederationException;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Compte de l'instance supprimé : ses abonnés distants reçoivent la suppression de son acteur (Delete), qui efface
 * chez eux son profil et ses publications. Envoyée aussitôt, et non en tâche de fond : sa clé de signature disparaît
 * avec le compte.
 */
#[AsEventListener]
#[WithMonologChannel('federation')]
final readonly class AccountDeletionBroadcaster
{
    public function __construct(
        private FollowRepository $follows,
        private FederationClient $client,
        private Signer $signer,
        private ApUrls $urls,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AccountDeleting $event): void
    {
        $account = $event->account;
        $inboxes = $this->follows->remoteFollowerInboxes($account);
        if ([] === $inboxes) {
            return;
        }
        $actor = $this->urls->actor($account);
        $json = json_encode([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $actor.'#delete',
            'type' => 'Delete',
            'actor' => $actor,
            'to' => [ApUrls::PUBLIC],
            'object' => $actor,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $credentials = $this->signer->credentialsOf($account);
        foreach ($inboxes as $inbox) {
            try {
                $this->client->post($inbox, $json, $credentials);
            } catch (FederationException $e) {
                $this->logger->warning('Suppression du compte non envoyée', ['inbox' => $inbox, 'reason' => $e->getMessage()]);
            }
        }
    }
}
