<?php

declare(strict_types=1);

namespace App\Integration\Immich\Application;

use App\Integration\Immich\Domain\ImmichConnection;
use App\Integration\Immich\Infrastructure\Doctrine\ImmichConnectionRepository;
use App\Integration\Immich\Infrastructure\ImmichClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class AddToImmichAlbumHandler
{
    public function __construct(
        private ImmichConnectionRepository $connections,
        private ImmichConnections $service,
        private ImmichClient $client,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(AddToImmichAlbum $message): void
    {
        $connection = $this->connections->find(Uuid::fromString($message->connectionId));
        if (!$connection instanceof ImmichConnection || !$connection->addsToAlbum() || [] === $message->assetIds) {
            return;
        }
        [$server, $key] = $this->service->credentials($connection);

        try {
            $albumId = $connection->getAlbumId();
            // L'album a pu être supprimé dans Immich : il est alors recréé.
            if (null === $albumId || !$this->client->albumExists($server, $key, $albumId)) {
                $albumId = $this->client->createAlbum($server, $key, ImmichConnection::ALBUM_NAME);
                $connection->rememberAlbum($albumId);
                $this->em->flush();
            }
            $this->client->addToAlbum($server, $key, $albumId, $message->assetIds);
        } catch (ImmichException $e) {
            // Clé refusée ou droits manquants : réessayer n'y changera rien.
            throw $e->permanent ? new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e) : $e;
        }
    }
}
