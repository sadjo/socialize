<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Infrastructure;

use App\Integration\Mastodon\Application\MastodonException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API d'un serveur Mastodon (ou compatible), en HTTPS. Le client HTTP refuse les adresses privées : un serveur
 * Mastodon est public, et l'adresse vient de la personne qui se connecte.
 */
final readonly class MastodonClient
{
    /** Droits demandés : lecture, et ce qu'il faut pour aimer, commenter, suivre et publier. */
    public const string SCOPES = 'read write:statuses write:media write:favourites write:follows';

    public function __construct(
        #[Autowire(service: 'mastodon.http_client')]
        private HttpClientInterface $client,
    ) {
    }

    /**
     * Enregistre l'application Socialize sur le serveur.
     *
     * @return array{clientId: string, clientSecret: string}
     */
    public function registerApp(string $domain, string $name, string $redirectUri, string $website): array
    {
        $app = $this->json('POST', $domain, '/api/v1/apps', null, ['json' => [
            'client_name' => $name, 'redirect_uris' => $redirectUri, 'scopes' => self::SCOPES, 'website' => $website,
        ]]);
        $id = \is_string($app['client_id'] ?? null) ? $app['client_id'] : '';
        $secret = \is_string($app['client_secret'] ?? null) ? $app['client_secret'] : '';
        if ('' === $id || '' === $secret) {
            throw new MastodonException(\sprintf('%s n’a pas accepté Socialize comme application.', $domain));
        }

        return ['clientId' => $id, 'clientSecret' => $secret];
    }

    /**
     * Adresse de la page d'autorisation du serveur (OAuth 2, avec PKCE).
     */
    public function authorizeUrl(string $domain, string $clientId, string $redirectUri, string $state, string $challenge): string
    {
        return 'https://'.$domain.'/oauth/authorize?'.http_build_query([
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirectUri, 'scope' => self::SCOPES,
            'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * Échange le code d'autorisation contre un jeton d'accès.
     *
     * @return array{token: string, scopes: string}
     */
    public function token(string $domain, string $clientId, #[\SensitiveParameter] string $clientSecret, string $redirectUri, string $code, string $verifier): array
    {
        $token = $this->json('POST', $domain, '/oauth/token', null, ['body' => [
            'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $clientId, 'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri, 'code_verifier' => $verifier, 'scope' => self::SCOPES,
        ]]);
        $access = \is_string($token['access_token'] ?? null) ? $token['access_token'] : '';
        if ('' === $access) {
            throw new MastodonException(\sprintf('%s n’a pas accordé l’accès.', $domain));
        }

        return ['token' => $access, 'scopes' => \is_string($token['scope'] ?? null) ? $token['scope'] : self::SCOPES];
    }

    /**
     * Compte du jeton : nom d'utilisateur et, si le serveur la donne, l'adresse ActivityPub de son acteur.
     *
     * @return array{username: string, actor: ?string}
     */
    public function verifyCredentials(string $domain, #[\SensitiveParameter] string $token): array
    {
        $me = $this->json('GET', $domain, '/api/v1/accounts/verify_credentials', $token);
        $username = \is_string($me['username'] ?? null) ? $me['username'] : '';
        if ('' === $username) {
            throw new MastodonException(\sprintf('%s n’a pas indiqué de compte.', $domain));
        }

        return ['username' => $username, 'actor' => \is_string($me['uri'] ?? null) && str_starts_with($me['uri'], 'https://') ? $me['uri'] : null];
    }

    /**
     * Révoque le jeton (déconnexion). Un échec n'empêche pas la déconnexion.
     */
    public function revoke(string $domain, string $clientId, #[\SensitiveParameter] string $clientSecret, #[\SensitiveParameter] string $token): void
    {
        try {
            $this->json('POST', $domain, '/oauth/revoke', null, ['body' => ['client_id' => $clientId, 'client_secret' => $clientSecret, 'token' => $token]]);
        } catch (MastodonException) {
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function json(string $method, string $domain, string $path, ?string $token, array $options = []): array
    {
        $headers = ['Accept' => 'application/json'];
        if (null !== $token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        try {
            $response = $this->client->request($method, 'https://'.$domain.$path, ['timeout' => 15, 'headers' => $headers] + $options);
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                throw new MastodonException(\sprintf('%s a refusé l’accès : reconnectez-vous avec Mastodon.', $domain));
            }
            if ($status >= 400) {
                throw new MastodonException(\sprintf('%s a répondu par une erreur (%d).', $domain, $status));
            }
            $data = json_decode($response->getContent(), true, 64, \JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface $e) {
            throw new MastodonException(\sprintf('Le serveur %s est injoignable.', $domain), $e->getCode(), previous: $e);
        } catch (\JsonException $e) {
            throw new MastodonException(\sprintf('%s n’a pas répondu comme un serveur Mastodon.', $domain), $e->getCode(), previous: $e);
        }

        return \is_array($data) ? $data : [];
    }
}
