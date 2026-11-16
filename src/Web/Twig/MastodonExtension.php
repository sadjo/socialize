<?php

declare(strict_types=1);

namespace App\Web\Twig;

use App\Integration\Mastodon\Application\MastodonLoginPolicy;
use Twig\Attribute\AsTwigFunction;

/**
 * Connexion avec un compte Mastodon dans les gabarits.
 */
final readonly class MastodonExtension
{
    public function __construct(
        private MastodonLoginPolicy $policy,
    ) {
    }

    /**
     * Serveurs permis pour la connexion Mastodon : null si elle est fermée, liste vide si tout serveur l'est.
     *
     * @return ?list<string>
     */
    #[AsTwigFunction('mastodon_login_servers')]
    public function servers(): ?array
    {
        return $this->policy->isEnabled() ? $this->policy->servers() : null;
    }
}
