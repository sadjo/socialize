<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Federation\FakeFediverse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Faux serveur Mastodon (API REST et OAuth) sur remote.test, le domaine de la fausse instance du fédivers : le compte
 * connecté est alice, dont l'acteur ActivityPub est servi par FakeFediverse. État statique : le noyau (donc ce
 * service) est recréé à chaque requête du client de test ; reset() au début de chaque test.
 */
final class FakeMastodon
{
    public const string DOMAIN = FakeFediverse::DOMAIN;
    public const string CODE = 'code-d-autorisation';
    public const string TOKEN = 'jeton-de-alice';

    /** Adresse d'acteur annoncée par verify_credentials (null : aucune, comme un serveur ancien). */
    public static ?string $actor = FakeFediverse::ALICE;

    /** @var list<array{method: string, url: string, body: array<string, mixed>, token: ?string}> */
    public static array $requests = [];

    public static function reset(): void
    {
        self::$actor = FakeFediverse::ALICE;
        self::$requests = [];
    }

    /**
     * @return list<array{method: string, url: string, body: array<string, mixed>, token: ?string}>
     */
    public static function requestsTo(string $method, string $path): array
    {
        return array_values(array_filter(self::$requests, static fn (array $r): bool => $r['method'] === $method && parse_url($r['url'], \PHP_URL_PATH) === $path));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $body = [];
        if (\is_string($options['body'] ?? null)) {
            $decoded = json_decode($options['body'], true);
            if (\is_array($decoded)) {
                $body = $decoded;
            } else {
                parse_str($options['body'], $body);
            }
        }
        $authorization = $this->header($options, 'authorization');
        $token = null !== $authorization && str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : null;
        $body = array_filter($body, \is_string(...), \ARRAY_FILTER_USE_KEY);
        self::$requests[] = ['method' => $method, 'url' => $url, 'body' => $body, 'token' => $token];

        if (!str_starts_with($url, 'https://'.self::DOMAIN.'/')) {
            return new MockResponse('', ['error' => 'Hôte inconnu']);
        }
        $path = (string) parse_url($url, \PHP_URL_PATH);

        return match (true) {
            'POST' === $method && '/api/v1/apps' === $path => $this->json(['id' => '1', 'client_id' => 'client-socialize', 'client_secret' => 'secret-socialize', 'name' => $body['client_name'] ?? '']),
            'POST' === $method && '/oauth/token' === $path => self::CODE === ($body['code'] ?? null) && 'secret-socialize' === ($body['client_secret'] ?? null)
                ? $this->json(['access_token' => self::TOKEN, 'token_type' => 'Bearer', 'scope' => $body['scope'] ?? '', 'created_at' => time()])
                : $this->json(['error' => 'invalid_grant'], 400),
            'POST' === $method && '/oauth/revoke' === $path => $this->json([]),
            'GET' === $method && '/api/v1/accounts/verify_credentials' === $path => self::TOKEN === $token
                ? $this->json(array_filter(['id' => '109', 'username' => 'alice', 'acct' => 'alice', 'display_name' => 'Alice', 'url' => 'https://remote.test/@alice', 'uri' => self::$actor]))
                : $this->json(['error' => 'The access token is invalid'], 401),
            default => $this->json(['error' => 'Record not found'], 404),
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function header(array $options, string $name): ?string
    {
        $headers = \is_array($options['normalized_headers'] ?? null) ? $options['normalized_headers'] : [];
        $lines = $headers[$name] ?? [];
        $line = \is_array($lines) ? ($lines[0] ?? null) : null;

        return \is_string($line) ? trim(substr($line, \strlen($name) + 1)) : null;
    }

    /**
     * @param array<mixed> $data
     */
    private function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
