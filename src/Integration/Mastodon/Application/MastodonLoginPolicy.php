<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Qui peut se connecter avec un compte Mastodon (variable MASTODON_LOGIN) : « closed » (personne, par défaut),
 * « open » (tout serveur), ou la liste des serveurs permis, séparés par des virgules (« mastodon.social,piaille.fr »).
 */
final readonly class MastodonLoginPolicy
{
    private bool $open;

    /** @var list<string> */
    private array $servers;

    public function __construct(
        #[Autowire(env: 'MASTODON_LOGIN')]
        string $setting,
    ) {
        $setting = mb_strtolower(trim($setting));
        $this->open = 'open' === $setting;
        $this->servers = \in_array($setting, ['', 'open', 'closed'], true) ? [] : array_values(array_filter(array_map(trim(...), explode(',', $setting))));
    }

    public function isEnabled(): bool
    {
        return $this->open || [] !== $this->servers;
    }

    public function allows(string $domain): bool
    {
        return $this->open || \in_array(mb_strtolower($domain), $this->servers, true);
    }

    /**
     * Serveurs permis, s'ils sont limités (sinon liste vide).
     *
     * @return list<string>
     */
    public function servers(): array
    {
        return $this->servers;
    }
}
