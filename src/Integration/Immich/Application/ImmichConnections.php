<?php

declare(strict_types=1);

namespace App\Integration\Immich\Application;

use App\Core\Domain\Account\Account;
use App\Integration\Immich\Domain\ImmichConnection;
use App\Integration\Immich\Infrastructure\Doctrine\ImmichConnectionRepository;
use App\Integration\Immich\Infrastructure\ImmichClient;
use App\Integration\Immich\Infrastructure\ImmichVault;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Connexion d'un compte à Immich : testée avant d'être enregistrée, clé chiffrée.
 */
final readonly class ImmichConnections
{
    public function __construct(
        private ImmichConnectionRepository $connections,
        private ImmichClient $client,
        private ImmichVault $vault,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function of(?Account $account): ?ImmichConnection
    {
        return $this->connections->forAccount($account);
    }

    /**
     * Teste l'adresse et la clé, puis les enregistre. Sans nouvelle clé, celle déjà enregistrée est gardée.
     *
     * @return array{user: string, version: string, images: int, videos: int, albums: int}
     */
    public function connect(Account $account, string $serverUrl, ?string $apiKey, bool $addToAlbum): array
    {
        $connection = $this->connections->forAccount($account);
        $apiKey = null !== $apiKey ? trim($apiKey) : '';
        if ('' === $apiKey) {
            $apiKey = $connection instanceof ImmichConnection ? $this->vault->decrypt($connection->getApiKeyEncrypted()) : throw new ImmichException('Saisissez la clé API créée dans Immich.');
        }
        $serverUrl = ImmichConnection::normalizeUrl($serverUrl);
        if (!preg_match('#^https?://[^/\s]+#i', $serverUrl)) {
            throw new ImmichException('Saisissez l’adresse du serveur Immich, par exemple http://photos.maison.lan:2283.');
        }

        $check = $this->client->check($serverUrl, $apiKey);

        $now = $this->clock->now();
        if ($connection instanceof ImmichConnection) {
            $connection->change($serverUrl, $this->vault->encrypt($apiKey));
        } else {
            $connection = new ImmichConnection($account, $serverUrl, $this->vault->encrypt($apiKey), $now);
            $this->em->persist($connection);
        }
        $connection->checked($check['user'], $check['version'], $now);
        $connection->chooseAddToAlbum($addToAlbum);
        $this->em->flush();

        return $check;
    }

    public function disconnect(Account $account): void
    {
        $connection = $this->connections->forAccount($account);
        if ($connection instanceof ImmichConnection) {
            $this->em->remove($connection);
            $this->em->flush();
        }
    }

    /**
     * @return array{0: string, 1: string} adresse du serveur et clé API en clair
     */
    public function credentials(ImmichConnection $connection): array
    {
        return [$connection->getServerUrl(), $this->vault->decrypt($connection->getApiKeyEncrypted())];
    }
}
