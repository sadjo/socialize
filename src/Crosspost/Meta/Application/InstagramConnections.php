<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Application;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Visibility;
use App\Crosspost\Meta\Domain\InstagramConnection;
use App\Crosspost\Meta\Infrastructure\Doctrine\InstagramConnectionRepository;
use App\Crosspost\Meta\Infrastructure\InstagramClient;
use App\Crosspost\Meta\Infrastructure\InstagramVault;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Connexion d'un compte à son Instagram professionnel (OAuth), jeton chiffré et renouvelé, règles par défaut.
 */
final readonly class InstagramConnections
{
    /** Renouvellement des jetons : une fois par semaine (un jeton doit avoir au moins 24 h). */
    public const string REFRESH_AFTER = '-7 days';

    public function __construct(
        private InstagramConnectionRepository $connections,
        private InstagramClient $client,
        private InstagramVault $vault,
        private InstagramApp $app,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->app->isConfigured();
    }

    public function of(?Account $account): ?InstagramConnection
    {
        return $this->connections->forAccount($account);
    }

    /**
     * Connexion utilisable pour publier (configurée, jeton valable).
     */
    public function usable(?Account $account): ?InstagramConnection
    {
        $connection = $this->of($account);

        return $this->isAvailable() && $connection instanceof InstagramConnection && !$connection->isExpired($this->clock->now()) ? $connection : null;
    }

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return $this->client->authorizeUrl($this->app->id, $redirectUri, $state);
    }

    /**
     * Retour de l'autorisation : code échangé contre un jeton long, compte Instagram relevé, connexion enregistrée.
     */
    public function connect(Account $account, #[\SensitiveParameter] string $code, string $redirectUri): InstagramConnection
    {
        if (!$this->isAvailable()) {
            throw new InstagramException('Le crosspost Instagram n’est pas configuré sur cette instance.');
        }
        $short = $this->client->exchangeCode($this->app->id, $this->app->secret, $redirectUri, $code);
        $missing = array_diff(InstagramClient::SCOPES, $short['permissions']);
        if ([] !== $short['permissions'] && [] !== $missing) {
            throw new InstagramException('Autorisation incomplète : acceptez la publication de contenu lors de la connexion à Instagram.');
        }
        $long = $this->client->longLived($this->app->secret, $short['token']);
        $me = $this->client->me($long['token']);
        $userId = '' !== $me['userId'] ? $me['userId'] : $short['userId'];
        if ('' === $userId) {
            throw new InstagramException('Instagram n’a pas donné l’identifiant du compte : réessayez la connexion.');
        }

        $now = $this->clock->now();
        $expiresAt = $now->modify(\sprintf('+%d seconds', $long['expiresIn']));
        $connection = $this->connections->forAccount($account);
        if ($connection instanceof InstagramConnection) {
            $connection->reconnect($userId, $me['username'], $this->vault->encrypt($long['token']), $expiresAt, $now);
        } else {
            $connection = new InstagramConnection($account, $userId, $me['username'], $this->vault->encrypt($long['token']), $expiresAt, $now);
            $this->em->persist($connection);
        }
        $this->em->flush();

        return $connection;
    }

    /**
     * @param list<Visibility> $visibilities
     */
    public function chooseRules(InstagramConnection $connection, array $visibilities, bool $stories): void
    {
        $connection->chooseRules($visibilities, $stories);
        $this->em->flush();
    }

    public function disconnect(Account $account): void
    {
        $connection = $this->connections->forAccount($account);
        if ($connection instanceof InstagramConnection) {
            $this->em->remove($connection);
            $this->em->flush();
        }
    }

    public function token(InstagramConnection $connection): string
    {
        return $this->vault->decrypt($connection->getTokenEncrypted());
    }

    /**
     * Jeton refusé par Instagram : le compte verra qu'il doit se reconnecter.
     */
    public function noteFailure(InstagramConnection $connection, InstagramException $failure): void
    {
        if ($failure->tokenRejected) {
            $connection->expire($this->clock->now());
            $this->em->flush();
        }
    }

    /**
     * Renouvelle les jetons qui ne l'ont pas été depuis une semaine (tâche planifiée).
     *
     * @return int jetons renouvelés
     */
    public function refreshDue(): int
    {
        $now = $this->clock->now();
        $renewed = 0;
        foreach ($this->connections->toRefresh($now->modify(self::REFRESH_AFTER)) as $connection) {
            if ($connection->getTokenExpiresAt() <= $now) {
                $connection->expire($now);
                continue;
            }
            try {
                $token = $this->client->refresh($this->token($connection));
                $connection->renew($this->vault->encrypt($token['token']), $now->modify(\sprintf('+%d seconds', $token['expiresIn'])), $now);
                ++$renewed;
            } catch (InstagramException $e) {
                if ($e->tokenRejected) {
                    $connection->expire($now);
                }
            }
        }
        $this->em->flush();

        return $renewed;
    }
}
