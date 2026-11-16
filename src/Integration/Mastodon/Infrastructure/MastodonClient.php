<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Infrastructure;

use App\Integration\Mastodon\Application\MastodonException;
use App\Integration\Mastodon\Application\MastodonLimits;
use App\Integration\Mastodon\Application\MastodonRateLimited;
use App\Integration\Mastodon\Application\MastodonSignupRejected;
use App\Integration\Mastodon\Application\MastodonUnconfirmed;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
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

    /** Droits de l'application elle-même : ceux des comptes, plus la création d'un compte (inscription). */
    public const string APP_SCOPES = self::SCOPES.' write:accounts';

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
            'client_name' => $name, 'redirect_uris' => $redirectUri, 'scopes' => self::APP_SCOPES, 'website' => $website,
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
     * Jeton de l'application elle-même (sans compte), pour créer un compte.
     */
    public function appToken(string $domain, string $clientId, #[\SensitiveParameter] string $clientSecret): string
    {
        $token = $this->json('POST', $domain, '/oauth/token', null, ['body' => [
            'grant_type' => 'client_credentials', 'client_id' => $clientId, 'client_secret' => $clientSecret, 'scope' => 'write:accounts',
        ]]);

        return \is_string($token['access_token'] ?? null) && '' !== $token['access_token']
            ? $token['access_token']
            : throw new MastodonException(\sprintf('%s n’a pas accordé à Socialize le droit de créer un compte.', $domain));
    }

    /**
     * Conditions d'inscription du serveur (/api/v2/instance) : ouverte ou non, sur approbation, âge minimal, règles.
     *
     * @return array{open: bool, approval: bool, minAge: ?int, rules: list<string>, message: ?string}
     */
    public function registration(string $domain): array
    {
        $instance = $this->json('GET', $domain, '/api/v2/instance', null);
        $registrations = \is_array($instance['registrations'] ?? null) ? $instance['registrations'] : [];
        $rules = [];
        foreach (\is_array($instance['rules'] ?? null) ? $instance['rules'] : [] as $rule) {
            if (\is_array($rule) && \is_string($rule['text'] ?? null) && '' !== trim($rule['text'])) {
                $rules[] = trim($rule['text']);
            }
        }

        return [
            'open' => true === ($registrations['enabled'] ?? null),
            'approval' => true === ($registrations['approval_required'] ?? null),
            'minAge' => is_numeric($registrations['min_age'] ?? null) && (int) $registrations['min_age'] > 0 ? (int) $registrations['min_age'] : null,
            'rules' => $rules,
            'message' => \is_string($registrations['message'] ?? null) && '' !== trim(strip_tags($registrations['message'])) ? trim(strip_tags($registrations['message'])) : null,
        ];
    }

    /**
     * Crée un compte sur le serveur. Le mot de passe et l'adresse ne font que passer.
     *
     * @param array{username: string, email: string, password: string, locale: string, reason?: ?string, date_of_birth?: ?string} $account
     *
     * @return string jeton du nouveau compte (utilisable une fois son adresse confirmée)
     *
     * @throws MastodonSignupRejected champs refusés par le serveur
     * @throws MastodonException
     */
    public function register(string $domain, #[\SensitiveParameter] string $appToken, #[\SensitiveParameter] array $account): string
    {
        $fields = array_filter($account + ['agreement' => 'true'], static fn (mixed $v): bool => null !== $v && '' !== $v);
        try {
            $response = $this->client->request('POST', 'https://'.$domain.'/api/v1/accounts', [
                'timeout' => 30, 'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$appToken], 'body' => $fields,
            ]);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (ExceptionInterface $e) {
            throw new MastodonException(\sprintf('Le serveur %s est injoignable.', $domain), $e->getCode(), previous: $e);
        }
        $data = \is_array($data) ? $data : [];
        if (422 === $status) {
            $errors = [];
            foreach (\is_array($data['details'] ?? null) ? $data['details'] : [] as $field => $details) {
                foreach (\is_array($details) ? $details : [] as $detail) {
                    if (\is_string($field) && \is_array($detail) && \is_string($detail['description'] ?? null)) {
                        $errors[$field][] = $detail['description'];
                    }
                }
            }
            throw new MastodonSignupRejected(\is_string($data['error'] ?? null) ? $data['error'] : 'Inscription refusée.', $errors);
        }
        if (429 === $status) {
            throw new MastodonRateLimited(\sprintf('%s reçoit trop d’inscriptions depuis Socialize : réessayez dans une demi-heure.', $domain), $this->retryAfter($response->getHeaders(false)));
        }
        if ($status >= 400) {
            throw new MastodonException(\sprintf('%s a refusé l’inscription (%d)%s', $domain, $status, \is_string($data['error'] ?? null) ? ' : '.$data['error'] : '.'));
        }

        return \is_string($data['access_token'] ?? null) && '' !== $data['access_token']
            ? $data['access_token']
            : throw new MastodonException(\sprintf('%s n’a pas créé le compte.', $domain));
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
     * Identifiant, sur ce serveur, d'une publication ou d'un compte du fédivers, cherché par son adresse ActivityPub
     * (le serveur va la chercher s'il ne la connaît pas encore).
     *
     * @param 'statuses'|'accounts' $type
     */
    public function resolve(string $domain, #[\SensitiveParameter] string $token, string $uri, string $type): ?string
    {
        $found = $this->json('GET', $domain, '/api/v2/search', $token, ['query' => ['q' => $uri, 'type' => $type, 'resolve' => 'true', 'limit' => 1]]);
        $first = \is_array($found[$type] ?? null) ? ($found[$type][0] ?? null) : null;

        return \is_array($first) && \is_string($first['id'] ?? null) ? $first['id'] : null;
    }

    /**
     * @param 'favourite'|'unfavourite' $action
     */
    public function favourite(string $domain, #[\SensitiveParameter] string $token, string $statusId, string $action = 'favourite'): void
    {
        $this->json('POST', $domain, '/api/v1/statuses/'.rawurlencode($statusId).'/'.$action, $token);
    }

    /**
     * Publie un statut (une réponse, ou une publication avec ses médias) ; la clé d'idempotence évite un doublon si
     * l'envoi est répété.
     *
     * @param 'public'|'unlisted'|'private'|'direct' $visibility
     * @param list<string>                           $mediaIds
     *
     * @return array{id: string, uri: string}
     */
    public function postStatus(string $domain, #[\SensitiveParameter] string $token, string $text, ?string $inReplyToId, string $visibility, string $idempotencyKey, array $mediaIds = [], ?string $spoiler = null): array
    {
        $status = $this->json('POST', $domain, '/api/v1/statuses', $token, [
            'json' => array_filter([
                'status' => $text, 'in_reply_to_id' => $inReplyToId, 'visibility' => $visibility,
                'media_ids' => [] !== $mediaIds ? $mediaIds : null,
                'spoiler_text' => $spoiler, 'sensitive' => null !== $spoiler ? true : null,
            ], static fn (mixed $v): bool => null !== $v),
            'headers' => ['Idempotency-Key' => $idempotencyKey],
            'timeout' => 60,
        ]);
        $id = \is_string($status['id'] ?? null) ? $status['id'] : '';
        $uri = \is_string($status['uri'] ?? null) ? $status['uri'] : '';
        if ('' === $id || '' === $uri) {
            throw new MastodonException(\sprintf('%s n’a pas publié le message.', $domain));
        }

        return ['id' => $id, 'uri' => $uri];
    }

    /**
     * Limites du serveur pour une publication.
     */
    public function limits(string $domain): MastodonLimits
    {
        return MastodonLimits::fromApi($this->json('GET', $domain, '/api/v2/instance', null));
    }

    /**
     * Envoie un média (avec son texte alternatif) ; le serveur le traite ensuite, parfois en différé (vidéo).
     *
     * @return array{id: string, ready: bool}
     */
    public function uploadMedia(string $domain, #[\SensitiveParameter] string $token, string $path, string $mimeType, ?string $description): array
    {
        $fields = ['file' => DataPart::fromPath($path, basename($path), $mimeType)];
        if (null !== $description && '' !== trim($description)) {
            $fields['description'] = trim($description);
        }
        $form = new FormDataPart($fields);
        $media = $this->json('POST', $domain, '/api/v2/media', $token, [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body' => $form->bodyToIterable(),
            'timeout' => 120,
            'max_duration' => 1800,
        ]);
        $id = \is_string($media['id'] ?? null) ? $media['id'] : '';
        if ('' === $id) {
            throw new MastodonException(\sprintf('%s n’a pas accepté le média.', $domain));
        }

        return ['id' => $id, 'ready' => \is_string($media['url'] ?? null)];
    }

    /**
     * Le média envoyé est-il traité (prêt à être joint à une publication) ?
     */
    public function mediaReady(string $domain, #[\SensitiveParameter] string $token, string $mediaId): bool
    {
        $media = $this->json('GET', $domain, '/api/v1/media/'.rawurlencode($mediaId), $token);

        return \is_string($media['url'] ?? null);
    }

    public function deleteStatus(string $domain, #[\SensitiveParameter] string $token, string $statusId): void
    {
        $this->json('DELETE', $domain, '/api/v1/statuses/'.rawurlencode($statusId), $token);
    }

    /**
     * @param 'follow'|'unfollow' $action
     *
     * @return bool vrai si le compte est suivi après l'appel (abonnement accepté)
     */
    public function follow(string $domain, #[\SensitiveParameter] string $token, string $accountId, string $action = 'follow'): bool
    {
        $relationship = $this->json('POST', $domain, '/api/v1/accounts/'.rawurlencode($accountId).'/'.$action, $token);

        return true === ($relationship['following'] ?? null);
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
     * Délai avant de réessayer après une erreur 429 : en-tête X-RateLimit-Reset de Mastodon (date), sinon Retry-After
     * (secondes), sinon 5 minutes.
     *
     * @param array<string, list<string>> $headers
     *
     * @return positive-int
     */
    private function retryAfter(array $headers): int
    {
        $reset = $headers['x-ratelimit-reset'][0] ?? null;
        if (null !== $reset && false !== ($at = strtotime($reset))) {
            return max(1, $at - time());
        }
        $after = $headers['retry-after'][0] ?? null;

        return null !== $after && is_numeric($after) ? max(1, (int) $after) : 300;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function json(string $method, string $domain, string $path, ?string $token, array $options = []): array
    {
        $headers = ['Accept' => 'application/json'];
        foreach (\is_array($options['headers'] ?? null) ? $options['headers'] : [] as $name => $value) {
            // En-têtes nommés, ou lignes « Nom: valeur » (formulaire multipart).
            if (\is_string($name)) {
                $headers[$name] = $value;
            } elseif (\is_string($value) && str_contains($value, ':')) {
                [$line, $content] = explode(':', $value, 2);
                $headers[trim($line)] = trim($content);
            }
        }
        unset($options['headers']);
        if (null !== $token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        try {
            $response = $this->client->request($method, 'https://'.$domain.$path, ['timeout' => 15, 'headers' => $headers] + $options);
            $status = $response->getStatusCode();
            if (403 === $status && str_contains(mb_strtolower($response->getContent(false)), 'confirm')) {
                throw new MastodonUnconfirmed(\sprintf('Votre adresse e-mail n’est pas encore confirmée : ouvrez l’e-mail envoyé par %s.', $domain));
            }
            if (401 === $status || 403 === $status) {
                throw new MastodonException(\sprintf('%s a refusé l’accès : reconnectez-vous avec Mastodon.', $domain));
            }
            if (429 === $status) {
                throw new MastodonRateLimited(\sprintf('%s limite le nombre d’envois : reprise plus tard.', $domain), $this->retryAfter($response->getHeaders(false)));
            }
            if (404 === $status) {
                throw new MastodonException(\sprintf('%s ne trouve pas cet élément.', $domain));
            }
            if (422 === $status) {
                $error = json_decode($response->getContent(false), true);
                throw new MastodonException(\sprintf('%s a refusé : %s', $domain, \is_array($error) && \is_string($error['error'] ?? null) ? $error['error'] : 'demande invalide.'));
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
