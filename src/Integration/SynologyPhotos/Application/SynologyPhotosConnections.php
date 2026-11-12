<?php

declare(strict_types=1);

namespace App\Integration\SynologyPhotos\Application;

use App\Core\Domain\Account\Account;
use App\Integration\SynologyPhotos\Domain\SynologyPhotosConnection;
use App\Integration\SynologyPhotos\Infrastructure\Doctrine\SynologyPhotosConnectionRepository;
use App\Integration\SynologyPhotos\Infrastructure\SynologyPhotosClient;
use App\Integration\SynologyPhotos\Infrastructure\SynologyPhotosVault;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Connexion d'un compte à Synology Photos : le mot de passe DSM ouvre une session, seule gardée (chiffrée).
 */
final readonly class SynologyPhotosConnections
{
    public function __construct(
        private SynologyPhotosConnectionRepository $connections,
        private SynologyPhotosClient $client,
        private SynologyPhotosVault $vault,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function of(?Account $account): ?SynologyPhotosConnection
    {
        return $this->connections->forAccount($account);
    }

    /**
     * Ouvre une session sur le NAS, la teste, puis l'enregistre. Le mot de passe et le code ne sont pas gardés.
     *
     * @return array{user: string, personal: int, team: ?int, albums: int}
     */
    public function connect(Account $account, string $serverUrl, string $userName, #[\SensitiveParameter] string $password, ?string $otp = null): array
    {
        $serverUrl = SynologyPhotosConnection::normalizeUrl($serverUrl);
        if (!preg_match('#^https?://[^/\s]+#i', $serverUrl)) {
            throw new SynologyPhotosException('Saisissez l’adresse du NAS, par exemple https://photos.exemple.fr ou https://nas.maison.lan:5001.');
        }
        $userName = trim($userName);
        if ('' === $userName || '' === $password) {
            throw new SynologyPhotosException('Saisissez l’identifiant et le mot de passe du compte DSM.');
        }

        $session = $this->client->login($serverUrl, $userName, $password, $otp);
        $check = $this->client->check($serverUrl, $session);

        $now = $this->clock->now();
        $connection = $this->connections->forAccount($account);
        if ($connection instanceof SynologyPhotosConnection) {
            $previous = $connection->isExpired() ? null : [$connection->getServerUrl(), $connection->getSessionEncrypted()];
            $connection->reconnect($serverUrl, $userName, $this->vault->encrypt($session), $now);
        } else {
            $previous = null;
            $connection = new SynologyPhotosConnection($account, $serverUrl, $userName, $this->vault->encrypt($session), $now);
            $this->em->persist($connection);
        }
        $this->em->flush();
        if (null !== $previous) {
            $this->close($previous[0], $previous[1]);
        }

        return $check;
    }

    /**
     * Oublie la connexion et ferme sa session sur le NAS (au mieux : un NAS injoignable n'empêche rien).
     */
    public function disconnect(Account $account): void
    {
        $connection = $this->connections->forAccount($account);
        if ($connection instanceof SynologyPhotosConnection) {
            $this->em->remove($connection);
            $this->em->flush();
            if (!$connection->isExpired()) {
                $this->close($connection->getServerUrl(), $connection->getSessionEncrypted());
            }
        }
    }

    /**
     * @return array{0: string, 1: string} adresse du NAS et identifiant de session en clair
     *
     * @throws SynologyPhotosException session déjà connue comme expirée
     */
    public function session(SynologyPhotosConnection $connection): array
    {
        if ($connection->isExpired()) {
            throw new SynologyPhotosException('La session Synology Photos a expiré : reconnectez-vous dans Réglages › Synology Photos.', sessionExpired: true);
        }

        return [$connection->getServerUrl(), $this->vault->decrypt($connection->getSessionEncrypted())];
    }

    /**
     * À appeler quand le NAS refuse la session : le compte verra qu'il doit se reconnecter.
     */
    public function noteFailure(SynologyPhotosConnection $connection, SynologyPhotosException $failure): void
    {
        if ($failure->sessionExpired && !$connection->isExpired()) {
            $connection->expire($this->clock->now());
            $this->em->flush();
        }
    }

    private function close(string $serverUrl, string $sessionEncrypted): void
    {
        try {
            $this->client->logout($serverUrl, $this->vault->decrypt($sessionEncrypted));
        } catch (SynologyPhotosException|\RuntimeException) {
            // Session déjà fermée ou NAS injoignable : rien à faire de plus.
        }
    }
}
