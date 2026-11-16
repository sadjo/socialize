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

    /** Le serveur traite les médias en différé (réponse 202, puis prêts à la première consultation). */
    public static bool $deferredMedia = false;

    /** Nombre maximal de médias par publication annoncé par le serveur. */
    public static int $maxMedia = 4;

    /** Comptes créés par l'API d'inscription (sans le mot de passe), et confirmation de leur adresse. */
    public const string NEW_TOKEN = 'jeton-du-nouveau-compte';
    /** @var list<array<string, mixed>> */
    public static array $signups = [];
    public static bool $confirmed = false;
    public static bool $registrationsOpen = true;

    /** Réglages du compte reçus (update_credentials), visibilité par défaut qui en découle, et refus éventuel. */
    /** @var list<array<string, mixed>> */
    public static array $settings = [];
    public static string $privacy = 'public';
    public static bool $settingsFail = false;

    /** Le serveur limite une fois le débit (erreur 429) à la prochaine publication. */
    public static bool $rateLimitOnce = false;

    /** Refus de la publication (erreur 422 avec ce message). */
    public static ?string $statusError = null;

    /** @var list<array{type: string, description: ?string}> médias reçus */
    public static array $media = [];

    public static function reset(): void
    {
        self::$actor = FakeFediverse::ALICE;
        self::$requests = [];
        self::$searchFails = false;
        self::$followAccepted = false;
        self::$known = [];
        self::$deferredMedia = false;
        self::$maxMedia = 4;
        self::$media = [];
        self::$statusError = null;
        self::$rateLimitOnce = false;
        self::$signups = [];
        self::$confirmed = false;
        self::$registrationsOpen = true;
        self::$settings = [];
        self::$privacy = 'public';
        self::$settingsFail = false;
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
        if (($options['body'] ?? null) instanceof \Closure) {
            // Formulaire multipart (envoi d'un média) : lu en entier.
            $raw = '';
            while ('' !== ($chunk = ($options['body'])(16384))) {
                $raw .= \is_string($chunk) ? $chunk : '';
            }
            preg_match('/Content-Type: ([^\r\n]+)\r\n(?:[^\r\n]+\r\n)*?Content-Disposition: form-data; name="file"/', $raw, $type);
            preg_match('/name="description"\r\n(?:[^\r\n]+\r\n)*\r\n(.*?)\r\n--/s', $raw, $description);
            $body = ['file' => $type[1] ?? '', 'description' => $description[1] ?? null];
        } elseif (\is_string($options['body'] ?? null)) {
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
            'POST' === $method && '/oauth/token' === $path && 'client_credentials' === ($body['grant_type'] ?? null) => 'write:accounts' === ($body['scope'] ?? null) && 'secret-socialize' === ($body['client_secret'] ?? null)
                ? $this->json(['access_token' => 'jeton-application', 'token_type' => 'Bearer', 'scope' => 'write:accounts'])
                : $this->json(['error' => 'invalid_scope'], 400),
            'POST' === $method && '/api/v1/accounts' === $path => $this->signup($body, $token),
            'POST' === $method && '/oauth/token' === $path => self::CODE === ($body['code'] ?? null) && 'secret-socialize' === ($body['client_secret'] ?? null)
                ? $this->json(['access_token' => self::TOKEN, 'token_type' => 'Bearer', 'scope' => $body['scope'] ?? '', 'created_at' => time()])
                : $this->json(['error' => 'invalid_grant'], 400),
            'POST' === $method && '/oauth/revoke' === $path => $this->json([]),
            'PATCH' === $method && '/api/v1/accounts/update_credentials' === $path => $this->updateCredentials($body, $token),
            'GET' === $method && '/api/v1/accounts/verify_credentials' === $path && self::NEW_TOKEN === $token && !self::$confirmed => $this->json(['error' => 'Your login is missing a confirmed e-mail address'], 403),
            'GET' === $method && '/api/v1/accounts/verify_credentials' === $path => \in_array($token, [self::TOKEN, self::NEW_TOKEN], true)
                ? $this->json(array_filter(['id' => '109', 'username' => 'alice', 'acct' => 'alice', 'display_name' => 'Alice', 'url' => 'https://remote.test/@alice', 'uri' => self::$actor, 'source' => ['privacy' => self::$privacy]]))
                : $this->json(['error' => 'The access token is invalid'], 401),
            'GET' === $method && '/api/v2/instance' === $path => $this->json(['domain' => self::DOMAIN,
                'registrations' => ['enabled' => self::$registrationsOpen, 'approval_required' => false, 'min_age' => 16, 'message' => null],
                'rules' => [['id' => '1', 'text' => 'Pas de contenu illégal.'], ['id' => '2', 'text' => 'Respectez les autres.']],
                'configuration' => [
                    'statuses' => ['max_characters' => 500, 'max_media_attachments' => self::$maxMedia],
                    'media_attachments' => ['supported_mime_types' => ['image/jpeg', 'image/png', 'image/heic', 'video/mp4', 'video/quicktime'], 'image_size_limit' => 16777216, 'video_size_limit' => 103809024],
                ]]),
            'POST' === $method && '/api/v2/media' === $path => $this->upload($body),
            'GET' === $method && (bool) preg_match('#^/api/v1/media/([^/]+)$#', $path, $m) => $this->json(['id' => $m[1], 'type' => 'image', 'url' => 'https://remote.test/media/'.$m[1].'.jpg']),
            'GET' === $method && '/api/v2/search' === $path => $this->search($url),
            'POST' === $method && (bool) preg_match('#^/api/v1/statuses/([^/]+)/(un)?favourite$#', $path, $m) => isset(self::$known[$m[1]])
                ? $this->json(['id' => $m[1], 'favourited' => '' === ($m[2] ?? '')])
                : $this->json(['error' => 'Record not found'], 404),
            'POST' === $method && '/api/v1/statuses' === $path && self::$rateLimitOnce => $this->rateLimited(),
            'POST' === $method && '/api/v1/statuses' === $path => null !== self::$statusError ? $this->json(['error' => self::$statusError], 422) : $this->status($body),
            'DELETE' === $method && (bool) preg_match('#^/api/v1/statuses/([^/]+)$#', $path, $m) => $this->json(['id' => $m[1]]),
            'POST' === $method && (bool) preg_match('#^/api/v1/accounts/([^/]+)/(un)?follow$#', $path, $m) => '' === ($m[2] ?? '')
                ? $this->json(['id' => $m[1], 'following' => self::$followAccepted, 'requested' => !self::$followAccepted])
                : $this->json(['id' => $m[1], 'following' => false, 'requested' => false]),
            default => $this->json(['error' => 'Record not found'], 404),
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private function signup(array $body, ?string $token): MockResponse
    {
        if ('jeton-application' !== $token) {
            return $this->json(['error' => 'This method requires an authenticated user'], 401);
        }
        if ('pris' === ($body['username'] ?? null)) {
            return $this->json(['error' => 'Validation failed: Username is already taken', 'details' => [
                'username' => [['error' => 'ERR_TAKEN', 'description' => 'est déjà utilisé']],
                'password' => [['error' => 'ERR_TOO_SHORT', 'description' => 'est trop court']],
            ]], 422);
        }
        unset($body['password']);
        self::$signups[] = $body;

        return $this->json(['access_token' => self::NEW_TOKEN, 'token_type' => 'Bearer', 'scope' => 'read write', 'created_at' => time()]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function updateCredentials(array $body, ?string $token): MockResponse
    {
        if (self::NEW_TOKEN === $token && !self::$confirmed) {
            return $this->json(['error' => 'Your login is missing a confirmed e-mail address'], 403);
        }
        if (self::$settingsFail) {
            return $this->json(['error' => 'Internal server error'], 500);
        }
        self::$settings[] = $body;
        $source = \is_array($body['source'] ?? null) ? $body['source'] : [];
        self::$privacy = \is_string($source['privacy'] ?? null) ? $source['privacy'] : self::$privacy;

        return $this->json(['id' => '109', 'username' => 'alice', 'locked' => 'true' === ($body['locked'] ?? null)]);
    }

    private function rateLimited(): MockResponse
    {
        self::$rateLimitOnce = false;

        return new MockResponse((string) json_encode(['error' => 'Too many requests']), ['http_code' => 429, 'response_headers' => [
            'content-type' => 'application/json', 'x-ratelimit-reset' => gmdate('Y-m-d\TH:i:s.000\Z', time() + 120),
        ]]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function upload(array $body): MockResponse
    {
        self::$media[] = ['type' => \is_string($body['file'] ?? null) ? $body['file'] : '', 'description' => \is_string($body['description'] ?? null) ? $body['description'] : null];
        $id = 'media-'.\count(self::$media);

        return self::$deferredMedia
            ? $this->json(['id' => $id, 'type' => 'video', 'url' => null], 202)
            : $this->json(['id' => $id, 'type' => 'image', 'url' => 'https://remote.test/media/'.$id.'.jpg']);
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
