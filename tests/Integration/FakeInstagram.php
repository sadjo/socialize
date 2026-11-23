<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestStorage;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Faux Instagram (API avec connexion Instagram) branché sur le client HTTP des tests : autorisation OAuth, jetons,
 * conteneurs, état des conteneurs, publication, lien et quota, avec les réponses documentées par Meta. Il lit les
 * photos envoyées (adresses /m/… de l'espace public des tests) pour vérifier qu'elles sont en JPEG. État statique
 * (le noyau est recréé à chaque requête du client de test) ; reset() au début de chaque test.
 */
final class FakeInstagram
{
    public const string CODE = 'code-autorisation';
    public const string USER_ID = '17841400000000001';
    public const string USERNAME = 'camille.photo';

    /** Jeton long en cours (changé à chaque renouvellement). */
    public static string $token = 'jeton-long-1';
    /** Vérifications « IN_PROGRESS » avant qu'un conteneur vidéo soit prêt. */
    public static int $videoChecks = 1;
    /** Le prochain conteneur créé finira en erreur. */
    public static bool $nextContainerFails = false;
    public static int $quotaUsed = 0;
    public static bool $down = false;

    /** @var array<string, array{params: array<string, string>, status: string, checks: int, image: ?array{width: int, height: int}}> */
    public static array $containers = [];
    /** @var list<string> conteneurs publiés, dans l'ordre */
    public static array $published = [];
    /** @var list<array{method: string, host: string, path: string, params: array<string, string>}> */
    public static array $requests = [];

    /** Clé secrète de l'application Meta attendue à l'échange du code. */
    public static string $secret = 'secret-de-test';
    /** Connexion unifiée : jeton d'utilisateur système (graph.facebook.com), sans expiration. */
    public const string SYSTEM_TOKEN = 'jeton-systeme';
    /** @var list<string> droits accordés par la configuration (connexion unifiée) */
    public static array $scopes = ['instagram_basic', 'instagram_content_publish', 'instagram_manage_contents'];
    /** @var list<string> publications supprimées (connexion unifiée) */
    public static array $deleted = [];
    /** Lien de publication refusé ce nombre de fois avant d'être donné. */
    public static int $permalinkMisses = 0;

    private static int $next = 0;

    public static function reset(): void
    {
        self::$token = 'jeton-long-1';
        self::$videoChecks = 1;
        self::$nextContainerFails = false;
        self::$quotaUsed = 0;
        self::$down = false;
        self::$containers = [];
        self::$published = [];
        self::$requests = [];
        self::$next = 0;
        self::$secret = 'secret-de-test';
        self::$scopes = ['instagram_basic', 'instagram_content_publish', 'instagram_manage_contents'];
        self::$deleted = [];
        self::$permalinkMisses = 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        if (self::$down) {
            return new MockResponse('', ['error' => 'Connexion refusée']);
        }
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        parse_str(\is_string($options['body'] ?? null) ? $options['body'] : '', $body);
        /** @var array<string, string> $params */
        $params = array_filter([...$query, ...$body], \is_string(...));
        $path = (string) parse_url($url, \PHP_URL_PATH);
        self::$requests[] = ['method' => $method, 'host' => (string) parse_url($url, \PHP_URL_HOST), 'path' => $path, 'params' => $params];
        $host = (string) parse_url($url, \PHP_URL_HOST);

        if ('api.instagram.com' === $host && 'POST' === $method && '/oauth/access_token' === $path) {
            return self::CODE === ($params['code'] ?? null) && self::$secret === ($params['client_secret'] ?? null) && 'authorization_code' === ($params['grant_type'] ?? null)
                ? $this->json(['data' => [['access_token' => 'jeton-court', 'user_id' => self::USER_ID, 'permissions' => 'instagram_business_basic,instagram_business_content_publish']]])
                : $this->error(400, 'Invalid authorization code', 36);
        }
        if ('graph.facebook.com' === $host) {
            // Connexion unifiée : échange du code, lecture du jeton ; le reste comme graph.instagram.com.
            if ('/v27.0/oauth/access_token' === $path) {
                return self::CODE === ($params['code'] ?? null) && self::$secret === ($params['client_secret'] ?? null)
                    ? $this->json(['access_token' => self::SYSTEM_TOKEN, 'token_type' => 'bearer'])
                    : $this->error(400, 'Invalid verification code format.', 100);
            }
            if ('/v27.0/debug_token' === $path) {
                return $this->json(['data' => ['is_valid' => true, 'expires_at' => 0, 'scopes' => self::$scopes]]);
            }
            if (self::SYSTEM_TOKEN !== ($params['access_token'] ?? null)) {
                return $this->error(400, 'Invalid OAuth access token', 190);
            }
            if ('/v27.0/me/assigned_instagram_accounts' === $path) {
                return $this->json(['data' => [['id' => self::USER_ID, 'username' => self::USERNAME]]]);
            }
            if ('DELETE' === $method && preg_match('#^/v27\.0/(media-\d+)$#', $path, $d)) {
                if (!\in_array('instagram_manage_contents', self::$scopes, true)) {
                    return $this->error(403, '(#10) Application does not have permission for this action', 10);
                }
                self::$deleted[] = $d[1];

                return $this->json(['success' => true, 'deleted_id' => $d[1]]);
            }
            $params['access_token'] = self::$token;
        } elseif ('graph.instagram.com' !== $host) {
            return new MockResponse('', ['error' => 'Hôte inconnu']);
        }
        if ('/access_token' === $path) {
            return 'jeton-court' === ($params['access_token'] ?? null) && 'ig_exchange_token' === ($params['grant_type'] ?? null)
                ? $this->json(['access_token' => self::$token, 'token_type' => 'bearer', 'expires_in' => 5183944])
                : $this->error(400, 'Invalid OAuth access token', 190);
        }
        if (self::$token !== ($params['access_token'] ?? null)) {
            return $this->error(400, 'Invalid OAuth access token', 190);
        }
        if ('/refresh_access_token' === $path) {
            self::$token = 'jeton-long-'.(1 + (int) substr(self::$token, 11));

            return $this->json(['access_token' => self::$token, 'token_type' => 'bearer', 'expires_in' => 5183944]);
        }
        if (!preg_match('#^/v27\.0/(.+)$#', $path, $m)) {
            return $this->error(400, 'Unknown path', 100);
        }
        $node = $m[1];

        return match (true) {
            'me' === $node => $this->json(['user_id' => self::USER_ID, 'username' => self::USERNAME, 'account_type' => 'Media_Creator']),
            self::USER_ID.'/content_publishing_limit' === $node => $this->json(['data' => [['quota_usage' => self::$quotaUsed, 'config' => ['quota_total' => 100, 'quota_duration' => 86400]]]]),
            'POST' === $method && self::USER_ID.'/media' === $node => $this->create($params),
            'POST' === $method && self::USER_ID.'/media_publish' === $node => $this->publish($params['creation_id'] ?? ''),
            isset(self::$containers[$node]) => $this->status($node),
            str_starts_with($node, 'media-') => self::$permalinkMisses-- > 0 ? $this->json(['id' => $node]) : $this->json(['id' => $node, 'permalink' => 'https://www.instagram.com/p/'.$node.'/']),
            default => $this->error(400, 'Unsupported get request', 100),
        };
    }

    /**
     * @return list<array{params: array<string, string>, status: string, checks: int, image: ?array{width: int, height: int}}>
     */
    public static function publishedContainers(): array
    {
        return array_map(static fn (string $id): array => self::$containers[$id], self::$published);
    }

    /**
     * @param array<string, string> $params
     */
    private function create(array $params): MockResponse
    {
        $image = null;
        if (isset($params['image_url'])) {
            // Instagram télécharge l'image : elle doit être publique et en JPEG.
            $file = TestStorage::dir('public/').preg_replace('#^https?://[^/]+/m/#', '', $params['image_url']);
            $size = is_file($file) ? getimagesize($file) : false;
            if (false === $size || \IMAGETYPE_JPEG !== $size[2]) {
                return $this->error(400, 'Only JPEG images are supported', 9004);
            }
            $image = ['width' => $size[0], 'height' => $size[1]];
        }
        if ('CAROUSEL' === ($params['media_type'] ?? null)) {
            foreach (explode(',', $params['children'] ?? '') as $child) {
                if ('FINISHED' !== (self::$containers[$child]['status'] ?? null)) {
                    return $this->error(400, 'Carousel item not ready', 9007);
                }
            }
        }
        $id = 'conteneur-'.++self::$next;
        $video = isset($params['video_url']);
        $status = self::$nextContainerFails ? 'ERROR' : ($video ? 'IN_PROGRESS' : 'FINISHED');
        self::$nextContainerFails = false;
        self::$containers[$id] = ['params' => $params, 'status' => $status, 'checks' => 0, 'image' => $image];

        return $this->json(['id' => $id]);
    }

    private function status(string $id): MockResponse
    {
        $container = &self::$containers[$id];
        if ('IN_PROGRESS' === $container['status'] && ++$container['checks'] > self::$videoChecks) {
            $container['status'] = 'FINISHED';
        }

        return $this->json(['id' => $id, 'status_code' => $container['status']]);
    }

    private function publish(string $id): MockResponse
    {
        if ('FINISHED' !== (self::$containers[$id]['status'] ?? null)) {
            return $this->error(400, 'Media ID is not available', 9007);
        }
        self::$containers[$id]['status'] = 'PUBLISHED';
        self::$published[] = $id;
        ++self::$quotaUsed;

        return $this->json(['id' => 'media-'.\count(self::$published)]);
    }

    /**
     * @param array<mixed> $data
     */
    private function json(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
    }

    private function error(int $status, string $message, int $code): MockResponse
    {
        return new MockResponse(json_encode(['error' => ['message' => $message, 'type' => 'OAuthException', 'code' => $code]], \JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
