<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

use App\Integration\Mastodon\Domain\MastodonUser;
use App\Integration\Mastodon\Infrastructure\MastodonClient;
use App\Integration\Mastodon\Infrastructure\MastodonVault;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Création d'un compte sur un serveur Mastodon (MASTODON_SIGNUP, par exemple mastodon.social) depuis Socialize,
 * par l'API d'inscription du serveur, puis connexion avec ce compte une fois son adresse e-mail confirmée.
 * L'adresse et le mot de passe ne font que passer : ni gardés, ni journalisés.
 */
final readonly class MastodonSignup
{
    private ?string $server;

    public function __construct(
        private MastodonClient $client,
        private MastodonLogin $login,
        private MastodonLoginPolicy $policy,
        private MastodonVault $vault,
        #[Autowire(service: 'mastodon.cache')]
        private CacheInterface $cache,
        #[Autowire(env: 'MASTODON_SIGNUP')]
        string $server,
    ) {
        $domain = '' !== trim($server) ? MastodonLogin::domainOf($server) : null;
        // Le compte créé doit pouvoir se connecter : le serveur doit aussi être permis par MASTODON_LOGIN.
        $this->server = null !== $domain && $this->policy->allows($domain) ? $domain : null;
    }

    /**
     * Serveur proposé pour créer un compte (null : pas de création de compte sur cette instance).
     */
    public function server(): ?string
    {
        return $this->server;
    }

    /**
     * Conditions d'inscription du serveur (gardées une heure) ; null si la création de compte n'est pas proposée ou
     * si le serveur ne répond pas.
     */
    public function registration(): ?MastodonRegistration
    {
        $domain = $this->server;
        if (null === $domain) {
            return null;
        }

        return $this->cache->get('mastodon_registration.'.hash('xxh128', $domain), function (ItemInterface $item) use ($domain): ?MastodonRegistration {
            try {
                $item->expiresAfter(3600);
                $registration = $this->client->registration($domain);

                return new MastodonRegistration($domain, $registration['open'], $registration['approval'], $registration['minAge'], $registration['rules'], $registration['message']);
            } catch (MastodonException) {
                $item->expiresAfter(60);

                return null;
            }
        });
    }

    /**
     * Crée le compte.
     *
     * @param array{username: string, email: string, password: string, reason?: ?string, dateOfBirth?: ?\DateTimeInterface} $account
     *
     * @return string jeton du nouveau compte, chiffré (en attente de la confirmation de son adresse)
     *
     * @throws MastodonSignupRejected champs refusés par le serveur
     * @throws MastodonException
     */
    public function register(#[\SensitiveParameter] array $account, string $redirectUri): string
    {
        $domain = $this->server ?? throw new MastodonException('La création de compte Mastodon n’est pas proposée sur cette instance.');
        $fields = [
            'username' => $account['username'],
            'email' => $account['email'],
            'password' => $account['password'],
            'locale' => 'fr',
            'reason' => $account['reason'] ?? null,
            'date_of_birth' => ($account['dateOfBirth'] ?? null)?->format('Y-m-d'),
        ];

        return $this->vault->encrypt($this->client->register($domain, $this->appToken($domain, $redirectUri), $fields));
    }

    /**
     * Connexion avec le compte créé, une fois son adresse confirmée.
     *
     * @throws MastodonUnconfirmed adresse pas encore confirmée
     * @throws MastodonException
     */
    public function confirm(string $domain, string $tokenEncrypted): MastodonUser
    {
        try {
            $token = $this->vault->decrypt($tokenEncrypted);
        } catch (\RuntimeException $e) {
            throw new MastodonException('Inscription interrompue : connectez-vous avec Mastodon.', $e->getCode(), previous: $e);
        }

        return $this->login->loginWithToken($domain, $token, MastodonClient::APP_SCOPES);
    }

    /**
     * Jeton de l'application Socialize sur le serveur ; une application enregistrée avant la création de compte
     * n'a pas ce droit : elle est refaite.
     */
    private function appToken(string $domain, string $redirectUri): string
    {
        $app = $this->login->app($domain, $redirectUri);
        try {
            return $this->client->appToken($domain, $app->getClientId(), $this->vault->decrypt($app->getClientSecretEncrypted()));
        } catch (MastodonRateLimited $e) {
            throw $e;
        } catch (MastodonException) {
            $app = $this->login->app($domain, $redirectUri, renew: true);

            return $this->client->appToken($domain, $app->getClientId(), $this->vault->decrypt($app->getClientSecretEncrypted()));
        }
    }
}
