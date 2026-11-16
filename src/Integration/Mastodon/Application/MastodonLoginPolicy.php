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
    /** Serveur mis en avant sur l'écran de connexion (le plus grand), quand il est permis. */
    public const string FEATURED = 'mastodon.social';

    /** Au-delà de ce nombre de serveurs permis, un champ remplace les boutons. */
    private const int MAX_BUTTONS = 3;

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
     * Ce que propose l'écran de connexion : un bouton par serveur (mastodon.social en premier), et un champ pour
     * en indiquer un autre (null : pas de champ ; liste vide : tout serveur ; sinon les serveurs proposés).
     *
     * @return ?array{buttons: list<string>, field: ?list<string>} null si la connexion Mastodon est fermée
     */
    public function choices(): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }
        if ($this->open) {
            return ['buttons' => [self::FEATURED], 'field' => []];
        }
        $servers = \in_array(self::FEATURED, $this->servers, true)
            ? [self::FEATURED, ...array_values(array_diff($this->servers, [self::FEATURED]))]
            : $this->servers;
        if (\count($servers) <= self::MAX_BUTTONS) {
            return ['buttons' => $servers, 'field' => null];
        }
        $buttons = self::FEATURED === $servers[0] ? [self::FEATURED] : [];

        return ['buttons' => $buttons, 'field' => array_values(array_diff($servers, $buttons))];
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
