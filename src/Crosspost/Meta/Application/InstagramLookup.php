<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Application;

use App\Core\Domain\Account\Account;
use App\Crosspost\Meta\Domain\InstagramConnection;
use App\Crosspost\Meta\Infrastructure\InstagramClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Vérifie un compte Instagram mentionné (« ig:@nom ») avec la connexion Instagram du compte qui écrit : nom affiché,
 * photo et abonnés d'un compte professionnel ou créateur. Possible seulement avec la connexion unifiée (Facebook) et
 * le droit pages_read_engagement ; sinon, la fonction est simplement indisponible pour ce compte.
 *
 * Une erreur de droits ne déconnecte jamais Instagram (le jeton reste bon pour publier) : la vérification est mise en
 * pause quelques heures pour ce compte.
 */
final readonly class InstagramLookup
{
    private const int FOUND_TTL = 86400;
    private const int MISSING_TTL = 3600;
    private const int OFF_TTL = 6 * 3600;

    public function __construct(
        private InstagramConnections $connections,
        private InstagramClient $client,
        #[Target('instagram.cache')]
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    public function isAvailable(?Account $account): bool
    {
        $connection = $this->connections->usable($account);

        return $connection instanceof InstagramConnection && $connection->isUnifiedLogin() && !$this->cache->get($this->offKey($connection), static fn (ItemInterface $item): bool => false);
    }

    /**
     * @return array{username: string, name: ?string, picture: ?string, followers: ?int}|null null : introuvable, ou
     *                                                                                        vérification indisponible
     */
    public function find(Account $account, string $username): ?array
    {
        $username = mb_strtolower(rtrim($username, '.'));
        $connection = $this->connections->usable($account);
        if (!preg_match('/^[a-z0-9_.]{1,30}$/', $username) || !$connection instanceof InstagramConnection || !$this->isAvailable($account)) {
            return null;
        }

        try {
            /** @var array{found: array{username: string, name: ?string, picture: ?string, followers: ?int}|null} $result */
            $result = $this->cache->get('ig-discovery-'.md5($username), function (ItemInterface $item) use ($connection, $username): array {
                $found = $this->client->businessDiscovery($connection->getInstagramUserId(), $this->connections->token($connection), $username);
                $item->expiresAfter(null !== $found ? self::FOUND_TTL : self::MISSING_TTL);

                return ['found' => $found];
            });

            return $result['found'];
        } catch (InstagramException $e) {
            if ($e->tokenRejected) {
                $this->cache->delete($this->offKey($connection));
                $this->cache->get($this->offKey($connection), static function (ItemInterface $item): bool {
                    $item->expiresAfter(self::OFF_TTL);

                    return true;
                });
            }
            $this->logger->info('Vérification Instagram impossible : {message}', ['message' => $e->getMessage()]);

            return null;
        }
    }

    private function offKey(InstagramConnection $connection): string
    {
        return 'ig-discovery-off-'.$connection->getId()->toRfc4122();
    }
}
