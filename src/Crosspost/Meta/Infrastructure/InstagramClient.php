<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Infrastructure;

use App\Crosspost\Meta\Application\InstagramException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API Instagram « avec connexion Instagram » (graph.instagram.com), pour publier sur un compte professionnel.
 *
 * Vérifié sur la documentation de Meta (novembre 2026) : autorisation sur www.instagram.com/oauth/authorize, échange
 * du code sur api.instagram.com, jeton long de 60 jours (ig_exchange_token, ig_refresh_token), conteneurs puis
 * media_publish, état des conteneurs (status_code), quota (content_publishing_limit). Le jeton part dans le corps des
 * requêtes POST et dans l'adresse des GET (comme le prévoit l'API), jamais dans les journaux.
 */
final readonly class InstagramClient
{
    public const string VERSION = 'v27.0';
    public const string GRAPH = 'https://graph.instagram.com';
    public const array SCOPES = ['instagram_business_basic', 'instagram_business_content_publish'];

    /** Jeton refusé (expiré, révoqué, droits retirés). */
    private const array TOKEN_ERRORS = [190, 102, 10, 200];
    /** Limites de débit : à réessayer plus tard. */
    private const array RATE_ERRORS = [4, 17, 32, 613, 9];

    public function __construct(
        #[Autowire(service: 'instagram.http_client')]
        private HttpClientInterface $client,
    ) {
    }

    public function authorizeUrl(string $appId, string $redirectUri, string $state): string
    {
        return 'https://www.instagram.com/oauth/authorize?'.http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(',', self::SCOPES),
            'state' => $state,
            // Connexion avec le compte Instagram lui-même (sans passer par Facebook).
            'enable_fb_login' => 'false',
        ]);
    }

    /**
     * Code d'autorisation → jeton court (1 h) et identifiant du compte.
     *
     * @return array{token: string, userId: string, permissions: list<string>}
     */
    public function exchangeCode(string $appId, #[\SensitiveParameter] string $appSecret, string $redirectUri, #[\SensitiveParameter] string $code): array
    {
        $data = $this->request('POST', 'https://api.instagram.com/oauth/access_token', ['body' => [
            'client_id' => $appId, 'client_secret' => $appSecret, 'grant_type' => 'authorization_code', 'redirect_uri' => $redirectUri, 'code' => $code,
        ]]);
        // Réponse documentée dans un tableau « data » ; certaines versions la donnent à plat.
        $first = \is_array($data['data'] ?? null) && \is_array($data['data'][0] ?? null) ? $data['data'][0] : $data;
        $token = $first['access_token'] ?? null;
        if (!\is_string($token) || '' === $token) {
            throw new InstagramException('Instagram n’a pas donné de jeton : réessayez la connexion.');
        }
        $permissions = \is_string($first['permissions'] ?? null) ? array_map(trim(...), explode(',', $first['permissions'])) : [];

        return ['token' => $token, 'userId' => \is_scalar($first['user_id'] ?? null) ? (string) $first['user_id'] : '', 'permissions' => array_values(array_filter($permissions))];
    }

    /**
     * Jeton court → jeton long (60 jours).
     *
     * @return array{token: string, expiresIn: int}
     */
    public function longLived(#[\SensitiveParameter] string $appSecret, #[\SensitiveParameter] string $shortToken): array
    {
        return $this->token($this->request('GET', self::GRAPH.'/access_token', ['query' => [
            'grant_type' => 'ig_exchange_token', 'client_secret' => $appSecret, 'access_token' => $shortToken,
        ]]));
    }

    /**
     * Renouvelle un jeton long (au moins 24 h d'âge, pas encore expiré) pour 60 nouveaux jours.
     *
     * @return array{token: string, expiresIn: int}
     */
    public function refresh(#[\SensitiveParameter] string $token): array
    {
        return $this->token($this->request('GET', self::GRAPH.'/refresh_access_token', ['query' => [
            'grant_type' => 'ig_refresh_token', 'access_token' => $token,
        ]]));
    }

    /**
     * @return array{userId: string, username: string, accountType: ?string}
     */
    public function me(#[\SensitiveParameter] string $token): array
    {
        $data = $this->request('GET', $this->graph('me'), ['query' => ['fields' => 'user_id,username,account_type', 'access_token' => $token]]);
        $me = \is_array($data['data'] ?? null) && \is_array($data['data'][0] ?? null) ? $data['data'][0] : $data;

        return [
            'userId' => \is_scalar($me['user_id'] ?? null) ? (string) $me['user_id'] : (\is_scalar($me['id'] ?? null) ? (string) $me['id'] : ''),
            'username' => \is_string($me['username'] ?? null) ? $me['username'] : '',
            'accountType' => \is_string($me['account_type'] ?? null) ? $me['account_type'] : null,
        ];
    }

    /**
     * Crée un conteneur (photo, vidéo, élément de carrousel, carrousel, Reel ou story).
     *
     * @param array<string, string> $params image_url, video_url, media_type, caption, alt_text, is_carousel_item, children…
     */
    public function createContainer(string $userId, #[\SensitiveParameter] string $token, array $params): string
    {
        $data = $this->request('POST', $this->graph(rawurlencode($userId).'/media'), ['body' => $params + ['access_token' => $token]]);

        return \is_scalar($data['id'] ?? null) ? (string) $data['id'] : throw new InstagramException('Instagram n’a pas créé le conteneur du média.');
    }

    /**
     * État d'un conteneur : IN_PROGRESS, FINISHED, ERROR, EXPIRED ou PUBLISHED.
     */
    public function containerStatus(string $containerId, #[\SensitiveParameter] string $token): string
    {
        $data = $this->request('GET', $this->graph(rawurlencode($containerId)), ['query' => ['fields' => 'status_code', 'access_token' => $token]]);

        return \is_string($data['status_code'] ?? null) ? $data['status_code'] : 'IN_PROGRESS';
    }

    public function publish(string $userId, #[\SensitiveParameter] string $token, string $containerId): string
    {
        $data = $this->request('POST', $this->graph(rawurlencode($userId).'/media_publish'), ['body' => ['creation_id' => $containerId, 'access_token' => $token]]);

        return \is_scalar($data['id'] ?? null) ? (string) $data['id'] : throw new InstagramException('Instagram n’a pas publié le média.');
    }

    public function permalink(string $mediaId, #[\SensitiveParameter] string $token): ?string
    {
        try {
            $data = $this->request('GET', $this->graph(rawurlencode($mediaId)), ['query' => ['fields' => 'permalink', 'access_token' => $token]]);
        } catch (InstagramException) {
            return null;
        }

        return \is_string($data['permalink'] ?? null) ? $data['permalink'] : null;
    }

    /**
     * Publications faites et quota sur 24 h glissantes (lu à chaque fois : la documentation donne 50 ou 100).
     *
     * @return array{used: int, total: ?int}
     */
    public function quota(string $userId, #[\SensitiveParameter] string $token): array
    {
        $data = $this->request('GET', $this->graph(rawurlencode($userId).'/content_publishing_limit'), ['query' => ['fields' => 'quota_usage,config', 'access_token' => $token]]);
        $first = \is_array($data['data'] ?? null) && \is_array($data['data'][0] ?? null) ? $data['data'][0] : [];
        $config = \is_array($first['config'] ?? null) ? $first['config'] : [];

        return [
            'used' => is_numeric($first['quota_usage'] ?? null) ? (int) $first['quota_usage'] : 0,
            'total' => is_numeric($config['quota_total'] ?? null) ? (int) $config['quota_total'] : null,
        ];
    }

    private function graph(string $path): string
    {
        return self::GRAPH.'/'.self::VERSION.'/'.$path;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array{token: string, expiresIn: int}
     */
    private function token(array $data): array
    {
        $token = $data['access_token'] ?? null;

        return [
            'token' => \is_string($token) && '' !== $token ? $token : throw new InstagramException('Instagram n’a pas donné de jeton.'),
            'expiresIn' => is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] : 60 * 86400,
        ];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function request(string $method, string $url, array $options): array
    {
        try {
            $response = $this->client->request($method, $url, $options + ['timeout' => 30]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new InstagramException('Instagram est injoignable pour le moment.', temporary: true, previous: $e);
        } catch (ExceptionInterface $e) {
            throw new InstagramException('Réponse d’Instagram illisible.', temporary: true, previous: $e);
        }
        $data = json_decode($content, true);
        $data = \is_array($data) ? $data : [];
        if ($status < 400 && !isset($data['error'])) {
            return $data;
        }

        $error = \is_array($data['error'] ?? null) ? $data['error'] : [];
        $code = is_numeric($error['code'] ?? null) ? (int) $error['code'] : 0;
        $message = \is_string($error['error_user_msg'] ?? null) ? $error['error_user_msg'] : (\is_string($error['message'] ?? null) ? $error['message'] : (\is_string($data['error_message'] ?? null) ? $data['error_message'] : ''));
        $detail = '' !== $message ? ' : '.$message : '';

        return match (true) {
            \in_array($code, self::TOKEN_ERRORS, true) || 401 === $status => throw new InstagramException('Instagram a refusé le jeton : reconnectez Instagram dans les réglages'.$detail, tokenRejected: true),
            \in_array($code, self::RATE_ERRORS, true) || 429 === $status || $status >= 500 => throw new InstagramException('Instagram est occupé pour le moment'.$detail, temporary: true),
            default => throw new InstagramException(\sprintf('Instagram a refusé la demande (%d)%s', $code ?: $status, $detail)),
        };
    }
}
