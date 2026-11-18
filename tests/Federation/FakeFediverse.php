<?php

declare(strict_types=1);

namespace App\Tests\Federation;

use App\Federation\Infrastructure\HttpSignature;
use App\Federation\Infrastructure\KeyVault;
use Jcupitt\Vips\Image;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fausse instance du fédivers (remote.test) branchée sur le client HTTP des tests :
 * elle sert WebFinger et l'acteur d'alice, et enregistre les activités qu'on lui livre.
 */
final class FakeFediverse
{
    public const string DOMAIN = 'remote.test';
    public const string ALICE = 'https://remote.test/users/alice';

    /** @var array{public: string, private: string}|null */
    private static ?array $keys = null;

    private static ?string $photo = null;

    /** @var list<array{url: string, headers: array<string, string>, body: string, activity: array<string, mixed>}> */
    public array $deliveries = [];

    /**
     * Activités de l'outbox d'alice, de la plus récente à la plus ancienne.
     * Statique : le noyau (et donc ce service) est recréé à chaque requête du client de test.
     *
     * @var list<array<string, mixed>>
     */
    public static array $outbox = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);

        if ('POST' === $method) {
            $headers = [];
            $lines = \is_array($options['headers'] ?? null) ? $options['headers'] : [];
            foreach ($lines as $line) {
                if (!\is_string($line) || !str_contains($line, ':')) {
                    continue;
                }
                [$name, $value] = explode(':', $line, 2);
                $headers[mb_strtolower(trim($name))] = trim($value);
            }
            $body = \is_string($options['body'] ?? null) ? $options['body'] : '';
            /** @var array<string, mixed> $activity */
            $activity = json_decode($body, true) ?? [];
            $this->deliveries[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'activity' => $activity];

            return new MockResponse('', ['http_code' => 202]);
        }

        // Médias des publications : une vraie photo JPEG pour /media/<numéro>.jpg (copiée ici par CacheRemoteMedia).
        if (1 === preg_match('#^/media/\d+\.jpg$#', $path)) {
            return new MockResponse(self::photo(), ['response_headers' => ['content-type' => 'image/jpeg']]);
        }

        if ('/.well-known/webfinger' === $path) {
            return $this->json(['subject' => 'acct:alice@remote.test', 'links' => [['rel' => 'self', 'type' => 'application/activity+json', 'href' => self::ALICE]]]);
        }
        if ('/users/alice' === $path) {
            return $this->json(self::actor());
        }
        if ('/users/alice/outbox' === $path) {
            // Comme Mastodon : la collection renvoie vers sa première page, paginée par 20.
            $page = (int) (parse_url($url, \PHP_URL_QUERY) ? substr(parse_url($url, \PHP_URL_QUERY), 5) : 0);
            if (0 === $page) {
                return $this->json(['id' => self::ALICE.'/outbox', 'type' => 'OrderedCollection', 'totalItems' => \count(self::$outbox), 'first' => self::ALICE.'/outbox?page=1']);
            }
            $items = \array_slice(self::$outbox, ($page - 1) * 20, 20);

            return $this->json(array_filter([
                'id' => self::ALICE.'/outbox?page='.$page,
                'type' => 'OrderedCollectionPage',
                'orderedItems' => $items,
                'next' => \count(self::$outbox) > $page * 20 ? self::ALICE.'/outbox?page='.($page + 1) : null,
            ]));
        }

        return new MockResponse('{"error":"not found"}', ['http_code' => 404]);
    }

    /**
     * Photo 4:5, comme celles des instances du fédivers (fabriquée une fois pour toute la suite de tests).
     */
    public static function photo(): string
    {
        return self::$photo ??= Image::black(1080, 1350, ['bands' => 3])->add([40, 60, 140])->cast('uchar')->writeToBuffer('.jpg', ['Q' => 80]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function actor(): array
    {
        return [
            '@context' => ['https://www.w3.org/ns/activitystreams', 'https://w3id.org/security/v1'],
            'id' => self::ALICE,
            'type' => 'Person',
            'preferredUsername' => 'alice',
            'name' => 'Alice Martin',
            'summary' => '<p>Photographe de nuit <a href="https://remote.test/tags/astro">#astro</a></p>',
            'inbox' => self::ALICE.'/inbox',
            'outbox' => self::ALICE.'/outbox',
            'url' => 'https://remote.test/@alice',
            'endpoints' => ['sharedInbox' => 'https://remote.test/inbox'],
            'icon' => ['type' => 'Image', 'url' => 'https://remote.test/avatars/alice.png'],
            'image' => ['type' => 'Image', 'url' => 'https://remote.test/headers/alice.png'],
            'publicKey' => ['id' => self::ALICE.'#main-key', 'owner' => self::ALICE, 'publicKeyPem' => self::keys()['public']],
        ];
    }

    /**
     * En-têtes « serveur » (HTTP_*) d'une requête POST signée par alice vers une boîte de réception locale.
     *
     * @return array<string, string>
     */
    public static function signedServer(string $url, string $body): array
    {
        $server = ['CONTENT_TYPE' => 'application/activity+json'];
        foreach (HttpSignature::sign('POST', $url, $body, self::ALICE.'#main-key', self::keys()['private']) as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    /**
     * @return list<array<string, mixed>> activités livrées, filtrées par type
     */
    public function delivered(string $type): array
    {
        return array_values(array_map(
            static fn (array $d): array => $d['activity'],
            array_filter($this->deliveries, static fn (array $d): bool => ($d['activity']['type'] ?? null) === $type),
        ));
    }

    /**
     * @return array{public: string, private: string}
     */
    private static function keys(): array
    {
        return self::$keys ??= KeyVault::generateRsaKeyPair();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/activity+json']]);
    }
}
