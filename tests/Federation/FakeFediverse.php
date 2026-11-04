<?php

declare(strict_types=1);

namespace App\Tests\Federation;

use App\Federation\Infrastructure\HttpSignature;
use App\Federation\Infrastructure\KeyVault;
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

    /** @var list<array{url: string, headers: array<string, string>, body: string, activity: array<string, mixed>}> */
    public array $deliveries = [];

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

        if ('/.well-known/webfinger' === $path) {
            return $this->json(['subject' => 'acct:alice@remote.test', 'links' => [['rel' => 'self', 'type' => 'application/activity+json', 'href' => self::ALICE]]]);
        }
        if ('/users/alice' === $path) {
            return $this->json(self::actor());
        }

        return new MockResponse('{"error":"not found"}', ['http_code' => 404]);
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
