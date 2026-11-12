<?php

declare(strict_types=1);

namespace App\Integration\Immich\Infrastructure;

use App\Integration\Immich\Application\ImmichAsset;
use App\Integration\Immich\Application\ImmichException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * API d'Immich (v3), avec la clé du compte (en-tête x-api-key).
 * Immich est le plus souvent sur le réseau local : les adresses privées sont permises.
 * Droits utilisés : user.read, asset.read, asset.view, asset.download, asset.statistics,
 * album.read, album.create, albumAsset.create.
 */
final readonly class ImmichClient
{
    public const int PAGE_SIZE = 60;

    public function __construct(
        #[Autowire(service: 'immich.http_client')]
        private HttpClientInterface $client,
    ) {
    }

    /**
     * Test de la connexion : compte, version du serveur et contenu de la photothèque.
     *
     * @return array{user: string, version: string, images: int, videos: int, albums: int}
     */
    public function check(string $server, string $key): array
    {
        $me = $this->json('GET', $server, $key, '/users/me');
        $version = $this->json('GET', $server, $key, '/server/version');
        $stats = $this->json('GET', $server, $key, '/assets/statistics');
        $albums = $this->json('GET', $server, $key, '/albums');

        return [
            'user' => trim(\sprintf('%s <%s>', \is_string($me['name'] ?? null) ? $me['name'] : '', \is_string($me['email'] ?? null) ? $me['email'] : '')),
            'version' => \sprintf('%d.%d.%d', $this->int($version, 'major'), $this->int($version, 'minor'), $this->int($version, 'patch')),
            'images' => $this->int($stats, 'images'),
            'videos' => $this->int($stats, 'videos'),
            'albums' => \count($albums),
        ];
    }

    /**
     * Photothèque, de la plus récente à la plus ancienne, éventuellement limitée à un album
     * et aux médias pris avant une date (pour aller directement à un jour).
     *
     * @return array{assets: list<ImmichAsset>, next: ?int}
     */
    public function library(string $server, string $key, int $page, ?string $albumId = null, ?\DateTimeImmutable $takenBefore = null): array
    {
        $query = ['page' => max(1, $page), 'size' => self::PAGE_SIZE, 'order' => 'desc', 'withExif' => true];
        if (null !== $albumId) {
            $query['albumIds'] = [$albumId];
        }
        if ($takenBefore instanceof \DateTimeImmutable) {
            $query['takenBefore'] = $takenBefore->format(\DATE_ATOM);
        }
        $result = $this->json('POST', $server, $key, '/search/metadata', ['json' => $query]);
        $assets = \is_array($result['assets'] ?? null) ? $result['assets'] : [];
        $items = \is_array($assets['items'] ?? null) ? $assets['items'] : [];

        return [
            'assets' => array_values(array_map(ImmichAsset::fromApi(...), array_filter($items, \is_array(...)))),
            'next' => is_numeric($assets['nextPage'] ?? null) ? (int) $assets['nextPage'] : null,
        ];
    }

    /**
     * @return list<array{id: string, name: string, count: int}>
     */
    public function albums(string $server, string $key): array
    {
        $albums = [];
        foreach ($this->json('GET', $server, $key, '/albums') as $album) {
            if (\is_array($album) && \is_string($album['id'] ?? null)) {
                $albums[] = ['id' => $album['id'], 'name' => \is_string($album['albumName'] ?? null) ? $album['albumName'] : '', 'count' => $this->int($album, 'assetCount')];
            }
        }
        usort($albums, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $albums;
    }

    public function asset(string $server, string $key, string $id): ImmichAsset
    {
        return ImmichAsset::fromApi($this->json('GET', $server, $key, '/assets/'.rawurlencode($id)));
    }

    /**
     * Vignette d'un média, à relayer telle quelle (la clé reste sur le serveur). « preview » : image en grand,
     * pour l'aperçu.
     *
     * @param 'thumbnail'|'preview' $size
     */
    public function thumbnail(string $server, string $key, string $id, string $size = 'thumbnail'): ResponseInterface
    {
        return $this->send('GET', $server, $key, '/assets/'.rawurlencode($id).'/thumbnail', ['query' => ['size' => $size]]);
    }

    /**
     * Vidéo lisible par un navigateur (version convertie par Immich, ou l'original), à relayer telle quelle.
     *
     * @param array<string, string> $headers en-têtes à transmettre (plage demandée)
     */
    public function playback(string $server, string $key, string $id, array $headers = []): ResponseInterface
    {
        return $this->send('GET', $server, $key, '/assets/'.rawurlencode($id).'/video/playback', ['headers' => $headers, 'timeout' => 60, 'max_duration' => 3600]);
    }

    /**
     * @return iterable<string>
     */
    public function chunks(ResponseInterface $response): iterable
    {
        try {
            foreach ($this->client->stream($response) as $chunk) {
                yield $chunk->getContent();
            }
        } catch (ExceptionInterface $e) {
            throw new ImmichException('La lecture depuis Immich a été interrompue.', previous: $e);
        }
    }

    /**
     * Télécharge l'original d'un média dans un fichier, par flux.
     */
    public function download(string $server, string $key, string $id, string $target): void
    {
        $response = $this->send('GET', $server, $key, '/assets/'.rawurlencode($id).'/original', ['timeout' => 120, 'max_duration' => 1800]);
        $out = fopen($target, 'w') ?: throw new ImmichException('Impossible d’écrire le fichier reçu d’Immich.');
        try {
            foreach ($this->chunks($response) as $chunk) {
                fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
        }
    }

    public function createAlbum(string $server, string $key, string $name): string
    {
        $album = $this->json('POST', $server, $key, '/albums', ['json' => ['albumName' => $name]]);

        return \is_string($album['id'] ?? null) ? $album['id'] : throw new ImmichException('Immich n’a pas créé l’album.');
    }

    public function albumExists(string $server, string $key, string $albumId): bool
    {
        try {
            $this->json('GET', $server, $key, '/albums/'.rawurlencode($albumId));

            return true;
        } catch (ImmichException $e) {
            if ($e->permanent) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * @param list<string> $assetIds
     */
    public function addToAlbum(string $server, string $key, string $albumId, array $assetIds): void
    {
        $this->json('PUT', $server, $key, '/albums/'.rawurlencode($albumId).'/assets', ['json' => ['ids' => $assetIds]]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function json(string $method, string $server, string $key, string $path, array $options = []): array
    {
        $response = $this->send($method, $server, $key, $path, $options + ['headers' => ['Accept' => 'application/json']]);
        try {
            $data = json_decode($response->getContent(), true, 64, \JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface|\JsonException $e) {
            throw new ImmichException('Réponse d’Immich illisible : l’adresse est-elle bien celle du serveur Immich ?', previous: $e);
        }

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $server, string $key, string $path, array $options = []): ResponseInterface
    {
        $headers = (\is_array($options['headers'] ?? null) ? $options['headers'] : []) + ['x-api-key' => $key];
        try {
            $response = $this->client->request($method, rtrim($server, '/').'/api'.$path, array_replace(['timeout' => 15], $options, ['headers' => $headers]));
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new ImmichException(\sprintf('Immich est injoignable à l’adresse %s.', $server), previous: $e);
        }

        return match (true) {
            401 === $status => throw new ImmichException('Clé API refusée par Immich : vérifiez-la dans les réglages.', permanent: true),
            403 === $status => throw new ImmichException('La clé API n’a pas tous les droits nécessaires (voir Réglages › Immich).', permanent: true),
            404 === $status => throw new ImmichException('Élément introuvable dans Immich.'),
            $status >= 400 => throw new ImmichException(\sprintf('Immich a répondu par une erreur (%d).', $status)),
            default => $response,
        };
    }

    /**
     * @param array<mixed> $data
     */
    private function int(array $data, string $key): int
    {
        return is_numeric($data[$key] ?? null) ? (int) $data[$key] : 0;
    }
}
