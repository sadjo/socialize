<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

use App\Core\Domain\Account\Account;
use App\Federation\Application\ApUrls;
use App\Federation\Application\BackfillAccount;
use App\Federation\Application\RemoteAccounts;
use App\Federation\Infrastructure\FederationException;
use App\Integration\Mastodon\Domain\MastodonApp;
use App\Integration\Mastodon\Domain\MastodonUser;
use App\Integration\Mastodon\Infrastructure\Doctrine\MastodonAppRepository;
use App\Integration\Mastodon\Infrastructure\Doctrine\MastodonUserRepository;
use App\Integration\Mastodon\Infrastructure\MastodonClient;
use App\Integration\Mastodon\Infrastructure\MastodonVault;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Connexion avec un compte Mastodon (OAuth 2 avec PKCE) : la personne autorise Socialize sur son serveur, puis
 * pilote son compte, connu ici comme compte distant. Le compte doit être hébergé sur le serveur qui l'a authentifié.
 */
final readonly class MastodonLogin
{
    public function __construct(
        private MastodonClient $client,
        private MastodonAppRepository $apps,
        private MastodonUserRepository $users,
        private MastodonVault $vault,
        private MastodonLoginPolicy $policy,
        private RemoteAccounts $remoteAccounts,
        private ApUrls $urls,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        #[Autowire(env: 'DEFAULT_URI')]
        private string $website,
    ) {
    }

    /**
     * Serveur indiqué par la personne : « mastodon.social », « https://mastodon.social/ » ou « @nom@mastodon.social ».
     */
    public static function domainOf(string $input): ?string
    {
        $input = mb_strtolower(trim($input));
        if (preg_match('/^@?[a-z0-9_.]+@([^@\s\/]+)$/', $input, $m)) {
            $input = $m[1];
        }
        $host = parse_url(str_contains($input, '://') ? $input : 'https://'.$input, \PHP_URL_HOST);
        if (!\is_string($host) || !preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            return null;
        }

        return $host;
    }

    /**
     * Début de la connexion : adresse de la page d'autorisation du serveur, et ce qu'il faut garder en session
     * pour le retour (état contre la falsification de requête, vérificateur PKCE).
     *
     * @return array{url: string, domain: string, state: string, verifier: string}
     */
    public function start(string $server, string $redirectUri): array
    {
        $domain = self::domainOf($server) ?? throw new MastodonException('Indiquez l’adresse de votre serveur Mastodon, par exemple mastodon.social.');
        $this->checkAllowed($domain);
        $app = $this->app($domain, $redirectUri);
        $state = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [
            'url' => $this->client->authorizeUrl($domain, $app->getClientId(), $redirectUri, $state, $challenge),
            'domain' => $domain,
            'state' => $state,
            'verifier' => $verifier,
        ];
    }

    /**
     * Retour du serveur : jeton obtenu, compte identifié, personne connectée (créée à sa première connexion), et
     * historique de son compte mis à jour.
     */
    public function finish(string $domain, string $code, string $verifier, string $redirectUri): MastodonUser
    {
        $this->checkAllowed($domain);
        $app = $this->apps->forDomain($domain) ?? throw new MastodonException('Connexion interrompue : recommencez.');
        $token = $this->client->token($domain, $app->getClientId(), $this->vault->decrypt($app->getClientSecretEncrypted()), $redirectUri, $code, $verifier);
        $account = $this->account($domain, $token['token']);

        $now = $this->clock->now();
        $user = $this->users->forAccount($account);
        if (!$user instanceof MastodonUser) {
            $user = new MastodonUser($account, $now);
            $this->em->persist($user);
        }
        $user->loggedIn($this->vault->encrypt($token['token']), $token['scopes'], $now);
        $this->em->flush();

        // Historique de son compte, pour son profil : à chaque connexion, puis régulièrement (RefreshMastodonAccounts).
        $this->bus->dispatch(new BackfillAccount($account->getId()->toRfc4122()));

        return $user;
    }

    /**
     * Déconnexion : jeton révoqué sur le serveur, puis effacé.
     */
    public function logout(MastodonUser $user): void
    {
        $user = $this->users->find($user->getId()) ?? $user;
        $token = $user->getTokenEncrypted();
        $domain = $user->getAccount()->getDomain();
        $app = null !== $domain ? $this->apps->forDomain($domain) : null;
        if (null !== $token && null !== $domain && $app instanceof MastodonApp) {
            try {
                $this->client->revoke($domain, $app->getClientId(), $this->vault->decrypt($app->getClientSecretEncrypted()), $this->vault->decrypt($token));
            } catch (\RuntimeException) {
                // Secret illisible : le jeton est tout de même effacé ici.
            }
        }
        $user->loggedOut();
        $this->em->flush();
    }

    private function checkAllowed(string $domain): void
    {
        if (!$this->policy->allows($domain)) {
            throw new MastodonException($this->policy->isEnabled() ? \sprintf('La connexion avec un compte de %s n’est pas ouverte sur cette instance.', $domain) : 'La connexion avec un compte Mastodon n’est pas ouverte sur cette instance.');
        }
        if ($domain === $this->urls->domain()) {
            throw new MastodonException('C’est l’adresse de cette instance : connectez-vous avec votre adresse e-mail.');
        }
    }

    /**
     * Application Socialize sur ce serveur : enregistrée à la première connexion, ou refaite si l'adresse de
     * retour de l'instance a changé.
     */
    private function app(string $domain, string $redirectUri): MastodonApp
    {
        $app = $this->apps->forDomain($domain);
        if ($app instanceof MastodonApp && $app->getRedirectUri() === $redirectUri) {
            return $app;
        }
        $registered = $this->client->registerApp($domain, \sprintf('Socialize (%s)', $this->urls->domain()), $redirectUri, $this->website);
        $secret = $this->vault->encrypt($registered['clientSecret']);
        if ($app instanceof MastodonApp) {
            $app->replace($registered['clientId'], $secret, $redirectUri, $this->clock->now());
        } else {
            $app = new MastodonApp($domain, $registered['clientId'], $secret, $redirectUri, $this->clock->now());
            $this->em->persist($app);
        }
        $this->em->flush();

        return $app;
    }

    /**
     * Compte distant du jeton. Son acteur ActivityPub doit être sur le serveur qui a délivré le jeton : un serveur ne
     * peut pas faire passer un de ses utilisateurs pour un compte d'un autre serveur.
     */
    private function account(string $domain, #[\SensitiveParameter] string $token): Account
    {
        $me = $this->client->verifyCredentials($domain, $token);
        try {
            $account = null !== $me['actor'] && $this->hostOf($me['actor']) === $domain
                ? $this->remoteAccounts->byUri($me['actor'], true)
                : $this->remoteAccounts->byHandle($me['username'], $domain);
        } catch (FederationException $e) {
            throw new MastodonException(\sprintf('Le profil de votre compte sur %s est introuvable : %s', $domain, $e->getMessage()), $e->getCode(), previous: $e);
        }
        if ($account->isLocal() || $this->hostOf((string) $account->getApUri()) !== $domain) {
            throw new MastodonException(\sprintf('Ce compte n’est pas hébergé sur %s.', $domain));
        }

        return $account;
    }

    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, \PHP_URL_HOST);

        return \is_string($host) ? mb_strtolower($host) : null;
    }
}
