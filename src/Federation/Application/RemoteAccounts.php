<?php

declare(strict_types=1);

namespace App\Federation\Application;

use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Federation\Infrastructure\FederationClient;
use App\Federation\Infrastructure\FederationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Trouve et met en cache les comptes des autres instances (acteurs ActivityPub).
 */
final readonly class RemoteAccounts
{
    private const string REFRESH_AFTER = '-1 day';
    private const array ACTOR_TYPES = ['Person', 'Service', 'Application', 'Group', 'Organization'];

    public function __construct(
        private FederationClient $client,
        private Signer $signer,
        private AccountRepository $accounts,
        private EntityManagerInterface $em,
        private ApUrls $urls,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Compte d'un acteur à partir de son URI. Le compte local correspondant si l'URI est locale.
     *
     * @throws FederationException
     */
    public function byUri(string $uri, bool $refresh = false): Account
    {
        if ($this->urls->isLocal($uri)) {
            $username = basename((string) parse_url($uri, \PHP_URL_PATH));

            return $this->accounts->findLocal($username) ?? throw new FederationException(\sprintf('Compte local inconnu : %s.', $uri));
        }

        $account = $this->accounts->findOneBy(['apUri' => $uri]);
        $stale = null === $account?->getFetchedAt() || $account->getFetchedAt() < $this->clock->now()->modify(self::REFRESH_AFTER);
        if (null !== $account && !$refresh && !$stale) {
            return $account;
        }

        $actor = $this->client->getJson($uri, $this->signer->instance());

        return $this->upsert($actor, $uri, $account);
    }

    /**
     * Compte « @nom@instance », trouvé par WebFinger.
     *
     * @throws FederationException
     */
    public function byHandle(string $username, string $domain): Account
    {
        $domain = mb_strtolower($domain);
        if ($domain === $this->urls->domain()) {
            return $this->accounts->findLocal($username) ?? throw new FederationException(\sprintf('Le compte @%s n’existe pas sur cette instance.', $username));
        }

        $existing = $this->accounts->findOneBy(['username' => mb_strtolower($username), 'domain' => $domain]);
        if (null !== $existing && null !== $existing->getApUri()) {
            return $this->byUri($existing->getApUri());
        }

        $jrd = $this->client->getJson(
            \sprintf('https://%s/.well-known/webfinger?resource=%s', $domain, rawurlencode('acct:'.$username.'@'.$domain)),
            null,
            'application/jrd+json, application/json',
        );
        foreach (Json::list($jrd, 'links') as $link) {
            if (\is_array($link) && 'self' === ($link['rel'] ?? null) && \is_string($link['href'] ?? null)
                && \in_array($link['type'] ?? '', ['application/activity+json', 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"'], true)) {
                return $this->byUri($link['href'], true);
            }
        }

        throw new FederationException(\sprintf('@%s@%s n’a pas de profil ActivityPub.', $username, $domain));
    }

    /**
     * @param array<string, mixed> $actor
     *
     * @throws FederationException
     */
    private function upsert(array $actor, string $uri, ?Account $account): Account
    {
        $id = Json::string($actor, 'id');
        $type = Json::string($actor, 'type');
        $username = Json::string($actor, 'preferredUsername');
        $inbox = Json::string($actor, 'inbox');
        if ($id !== $uri || !\in_array($type, self::ACTOR_TYPES, true) || null === $username || null === $inbox) {
            throw new FederationException(\sprintf('%s n’est pas un acteur ActivityPub valide.', $uri));
        }
        $domain = Json::host($id) ?? throw new FederationException('Adresse d’acteur invalide.');
        if (Json::host($inbox) !== $domain) {
            throw new FederationException('La boîte de réception de l’acteur n’est pas sur son instance.');
        }

        $account ??= $this->accounts->findOneBy(['username' => mb_strtolower($username), 'domain' => $domain]);
        if (null === $account) {
            $account = Account::remote($username, $domain, $id);
            $this->em->persist($account);
        }

        $endpoints = Json::object($actor, 'endpoints') ?? [];
        $publicKey = Json::object($actor, 'publicKey') ?? [];
        $icon = Json::object($actor, 'icon') ?? [];

        $account->refreshRemote(
            displayName: HtmlText::toText(Json::string($actor, 'name')) ?: $username,
            bio: HtmlText::toText(Json::string($actor, 'summary')),
            inboxUrl: $inbox,
            sharedInboxUrl: Json::string($endpoints, 'sharedInbox'),
            outboxUrl: Json::string($actor, 'outbox'),
            profileUrl: Json::id($actor, 'url'),
            avatarUrl: Json::id($icon, 'url'),
            publicKeyPem: Json::string($publicKey, 'publicKeyPem'),
            now: $this->clock->now(),
        );
        $this->em->flush();

        return $account;
    }
}
