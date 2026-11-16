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

    /** Le serveur ne retrouve aucune publication ni aucun compte (recherche vide). */
    public static bool $searchFails = false;

    /** Abonnement accepté aussitôt (compte non verrouillé sur un serveur qui répond tout de suite). */
    public static bool $followAccepted = false;

    /** @var array<string, string> adresse ActivityPub par identifiant connu du serveur */
    public static array $known = [];

    public static function reset(): void
    {
        self::$actor = FakeFediverse::ALICE;
        self::$requests = [];
        self::$searchFails = false;
        self::$followAccepted = false;
        self::$known = [];
    }

    /**
     * Identifiant donné par le faux serveur à une publication ou un compte du fédivers.
     */
    public static function idOf(string $uri): string
    {
        return substr(hash('sha256', $uri), 0, 12);
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
            'GET' === $method && '/api/v2/search' === $path => $this->search($url),
            'POST' === $method && (bool) preg_match('#^/api/v1/statuses/([^/]+)/(un)?favourite$#', $path, $m) => isset(self::$known[$m[1]])
                ? $this->json(['id' => $m[1], 'favourited' => '' === ($m[2] ?? '')])
                : $this->json(['error' => 'Record not found'], 404),
            'POST' === $method && '/api/v1/statuses' === $path => $this->status($body),
            'DELETE' === $method && (bool) preg_match('#^/api/v1/statuses/([^/]+)$#', $path, $m) => $this->json(['id' => $m[1]]),
            'POST' === $method && (bool) preg_match('#^/api/v1/accounts/([^/]+)/(un)?follow$#', $path, $m) => '' === ($m[2] ?? '')
                ? $this->json(['id' => $m[1], 'following' => self::$followAccepted, 'requested' => !self::$followAccepted])
                : $this->json(['id' => $m[1], 'following' => false, 'requested' => false]),
            default => $this->json(['error' => 'Record not found'], 404),
        };
    }

    private function search(string $url): MockResponse
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $type = \is_string($query['type'] ?? null) ? $query['type'] : '';
        $uri = \is_string($query['q'] ?? null) ? $query['q'] : '';
        if (self::$searchFails || !\in_array($type, ['statuses', 'accounts'], true) || !str_starts_with($uri, 'http')) {
            return $this->json(['accounts' => [], 'statuses' => [], 'hashtags' => []]);
        }
        self::$known[self::idOf($uri)] = $uri;

        $results = ['accounts' => [], 'statuses' => [], 'hashtags' => []];
        $results[$type] = [['id' => self::idOf($uri), 'uri' => $uri]];

        return $this->json($results);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function status(array $body): MockResponse
    {
        $uri = 'https://remote.test/users/alice/statuses/'.(\count(self::requestsTo('POST', '/api/v1/statuses')) + 99);
        self::$known[self::idOf($uri)] = $uri;

        return $this->json(['id' => self::idOf($uri), 'uri' => $uri, 'content' => $body['status'] ?? '', 'visibility' => $body['visibility'] ?? 'public', 'in_reply_to_id' => $body['in_reply_to_id'] ?? null]);
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
