<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Application;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Visibility;
use App\Crosspost\Meta\Domain\InstagramAppSettings;
use App\Crosspost\Meta\Domain\InstagramConnection;
use App\Crosspost\Meta\Infrastructure\Doctrine\InstagramAppSettingsRepository;
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
        private InstagramAppSettingsRepository $apps,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Le compte peut-il connecter son Instagram ? (application Meta à lui, ou celle de l'instance).
     */
    public function isAvailable(?Account $account): bool
    {
        return $this->appFor($account) instanceof InstagramApp;
    }

    public function hasInstanceApp(): bool
    {
        return $this->app->isConfigured();
    }

    /**
     * Application Meta utilisée pour connecter le compte : la sienne si elle est réglée, sinon celle de l'instance.
     */
    public function appFor(?Account $account): ?InstagramApp
    {
        $own = $this->apps->forAccount($account);
        if ($own instanceof InstagramAppSettings) {
            return new InstagramApp($own->getAppId(), $this->vault->decrypt($own->getSecretEncrypted()), $own->getConfigId());
        }

        return $this->app->isConfigured() ? $this->app : null;
    }

    public function ownApp(?Account $account): ?InstagramAppSettings
    {
        return $this->apps->forAccount($account);
    }

    /**
     * Application Meta propre au compte. Clé secrète vide : celle déjà enregistrée est gardée. Identifiant de
     * configuration : connexion unifiée (applications récentes).
     */
    public function saveApp(Account $account, string $appId, #[\SensitiveParameter] ?string $secret, ?string $configId = null): void
    {
        $appId = trim($appId);
        $configId = null === $configId || '' === trim($configId) ? null : trim($configId);
        $secret = null === $secret || '' === trim($secret) ? null : trim($secret);
        $own = $this->apps->forAccount($account);
        if (!$own instanceof InstagramAppSettings) {
            if (null === $secret) {
                throw new InstagramException('Indiquez la clé secrète de l’application.');
            }
            $own = new InstagramAppSettings($account, $appId, $this->vault->encrypt($secret), $this->clock->now());
            $own->change($appId, null, $this->clock->now(), $configId);
            $this->em->persist($own);
        } else {
            $own->change($appId, null === $secret ? null : $this->vault->encrypt($secret), $this->clock->now(), $configId);
        }
        $this->em->flush();
    }

    /**
     * Application propre retirée : la connexion déjà faite reste valable (le jeton ne dépend pas de la clé secrète).
     */
    public function removeApp(Account $account): void
    {
        $own = $this->apps->forAccount($account);
        if ($own instanceof InstagramAppSettings) {
            $this->em->remove($own);
            $this->em->flush();
        }
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

        // Le jeton suffit pour publier : la connexion reste utilisable même sans application réglée.
        return $connection instanceof InstagramConnection && !$connection->isExpired($this->clock->now()) ? $connection : null;
    }

    public function authorizeUrl(Account $account, string $redirectUri, string $state): string
    {
        $app = $this->appFor($account) ?? throw new InstagramException('Aucune application Meta n’est réglée pour ce compte.');

        return $app->isUnified()
            ? $this->client->unifiedAuthorizeUrl($app->id, (string) $app->configId, $redirectUri, $state)
            : $this->client->authorizeUrl($app->id, $redirectUri, $state);
    }

    /**
     * Retour de l'autorisation : code échangé contre un jeton long, compte Instagram relevé, connexion enregistrée.
     */
    public function connect(Account $account, #[\SensitiveParameter] string $code, string $redirectUri): InstagramConnection
    {
        $app = $this->appFor($account) ?? throw new InstagramException('Aucune application Meta n’est réglée pour ce compte.');
        if ($app->isUnified()) {
            return $this->connectUnified($account, $app, $code, $redirectUri);
        }
        $short = $this->client->exchangeCode($app->id, $app->secret, $redirectUri, $code);
        $missing = array_diff(InstagramClient::SCOPES, $short['permissions']);
        if ([] !== $short['permissions'] && [] !== $missing) {
            throw new InstagramException('Autorisation incomplète : acceptez la publication de contenu lors de la connexion à Instagram.');
        }
        $long = $this->client->longLived($app->secret, $short['token']);
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
     * Connexion unifiée : jeton d'utilisateur système (sans expiration si la configuration le prévoit), compte
     * Instagram confié à la configuration.
     */
    private function connectUnified(Account $account, InstagramApp $app, #[\SensitiveParameter] string $code, string $redirectUri): InstagramConnection
    {
        $grant = $this->client->unifiedExchange($app->id, $app->secret, $redirectUri, $code);
        $missing = array_diff(InstagramClient::UNIFIED_SCOPES, $grant['scopes']);
        if ([] !== $grant['scopes'] && [] !== $missing) {
            throw new InstagramException(\sprintf('Autorisation incomplète : la configuration de l’application Meta doit accorder %s.', implode(' et ', $missing)));
        }
        $accounts = $this->client->unifiedAccounts($grant['token']);
        if ([] === $accounts) {
            throw new InstagramException('Aucun compte Instagram professionnel n’est confié à cette configuration : ajoutez-le comme ressource (« Compte Instagram ») dans la configuration de l’application Meta.');
        }
        $ig = $accounts[0];

        $now = $this->clock->now();
        // Sans expiration (utilisateur système) : gardée dix ans, sans renouvellement.
        $expiresAt = null !== $grant['expiresAt'] ? $now->setTimestamp($grant['expiresAt']) : $now->modify('+10 years');
        $connection = $this->connections->forAccount($account);
        if ($connection instanceof InstagramConnection) {
            $connection->reconnect($ig['userId'], $ig['username'], $this->vault->encrypt($grant['token']), $expiresAt, $now, unifiedLogin: true);
        } else {
            $connection = new InstagramConnection($account, $ig['userId'], $ig['username'], $this->vault->encrypt($grant['token']), $expiresAt, $now);
            $connection->viaUnifiedLogin();
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
            // Connexion unifiée : jeton d'utilisateur système, sans renouvellement (reconnexion à son expiration).
            if ($connection->isUnifiedLogin()) {
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
