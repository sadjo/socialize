<?php

declare(strict_types=1);

namespace App\Federation\Application;

use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Federation\Infrastructure\FederationClient;
use App\Federation\Infrastructure\FederationException;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Historique d'un compte distant : lit son outbox (du plus récent au plus ancien) et importe
 * ses publications des six derniers mois, dans la limite des 50 dernières, avec ou sans média.
 * Relève aussi ses nombres d'abonnés, d'abonnements et de publications (totalItems de ses collections).
 */
#[AsMessageHandler]
#[WithMonologChannel('federation')]
final readonly class BackfillAccountHandler
{
    public const int MAX_POSTS = 50;
    public const string MAX_AGE = '-6 months';
    private const int MAX_PAGES = 10;

    public function __construct(
        private AccountRepository $accounts,
        private FederationClient $client,
        private Signer $signer,
        private InboxProcessor $inbox,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(BackfillAccount $message): void
    {
        $account = $this->accounts->find(Uuid::fromString($message->accountId));
        if (!$account instanceof Account || $account->isLocal() || null === $account->getOutboxUrl()) {
            return;
        }

        try {
            $imported = $this->backfill($account);
            $this->logger->info('Historique récupéré', ['actor' => $account->getApUri(), 'posts' => $imported]);
        } catch (FederationException $e) {
            $this->logger->notice('Historique non récupéré', ['actor' => $account->getApUri(), 'reason' => $e->getMessage()]);
        }
        $account->markBackfilled($this->clock->now());
        $this->em->flush();
    }

    /**
     * Nombres donnés par l'instance du compte : abonnés et abonnements (collections de son acteur), publications
     * (outbox). Une collection illisible ou sans totalItems laisse le nombre inconnu.
     *
     * @param array<string, mixed> $outbox
     */
    private function readCounts(Account $account, array $outbox): void
    {
        $signer = $this->signer->instance();
        $total = static fn (array $collection): ?int => \is_int($collection['totalItems'] ?? null) && $collection['totalItems'] >= 0 ? $collection['totalItems'] : null;
        $count = function (?string $url) use ($signer, $total): ?int {
            if (null === $url) {
                return null;
            }
            try {
                return $total($this->client->getJson($url, $signer));
            } catch (FederationException) {
                return null;
            }
        };
        try {
            $actor = $this->client->getJson((string) $account->getApUri(), $signer);
        } catch (FederationException) {
            $actor = [];
        }
        $account->updateRemoteCounts($count(Json::id($actor, 'followers')), $count(Json::id($actor, 'following')), $total($outbox));
        $this->em->flush();
    }

    /**
     * @return int nombre de publications examinées
     *
     * @throws FederationException
     */
    private function backfill(Account $account): int
    {
        $signer = $this->signer->instance();
        $since = $this->clock->now()->modify(self::MAX_AGE);

        $outbox = $this->client->getJson((string) $account->getOutboxUrl(), $signer);
        $this->readCounts($account, $outbox);
        // La première page est soit incluse dans la collection, soit à télécharger.
        $page = Json::object($outbox, 'first') ?? (null !== Json::id($outbox, 'first') ? $this->client->getJson(Json::id($outbox, 'first'), $signer) : $outbox);

        $seen = 0;
        for ($pages = 1;; ++$pages) {
            foreach ([...Json::list($page, 'orderedItems'), ...Json::list($page, 'items')] as $item) {
                if (!\is_array($item) || 'Create' !== ($item['type'] ?? null)) {
                    continue; // partages (Announce) et autres activités ignorés
                }
                $note = Json::object(Json::keyed($item), 'object');
                if (null === $note || null !== Json::id($note, 'inReplyTo')) {
                    continue; // objet non inclus ou réponse à une conversation
                }
                if ($this->publishedAt($note) < $since) {
                    return $seen; // l'outbox est triée du plus récent au plus ancien
                }

                $this->inbox->importFromHistory($note, $account);
                if (++$seen >= self::MAX_POSTS) {
                    return $seen;
                }
            }

            $next = Json::id($page, 'next');
            if (null === $next || $pages >= self::MAX_PAGES) {
                return $seen;
            }
            $page = $this->client->getJson($next, $signer);
        }
    }

    /**
     * @param array<string, mixed> $note
     */
    private function publishedAt(array $note): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable(Json::string($note, 'published') ?? 'now');
        } catch (\Exception) {
            return $this->clock->now();
        }
    }
}
