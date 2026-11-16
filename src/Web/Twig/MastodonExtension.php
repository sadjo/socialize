<?php

declare(strict_types=1);

namespace App\Web\Twig;

use App\Integration\Mastodon\Application\MastodonLoginPolicy;
use App\Integration\Mastodon\Domain\MastodonPublication;
use App\Integration\Mastodon\Domain\MastodonUser;
use App\Integration\Mastodon\Infrastructure\Doctrine\MastodonPublicationRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/**
 * Connexion avec un compte Mastodon dans les gabarits.
 */
final readonly class MastodonExtension
{
    public function __construct(
        private MastodonLoginPolicy $policy,
        private MastodonPublicationRepository $publications,
        private Security $security,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Publications de la personne connectée avec Mastodon en cours d'envoi, ou en échec depuis moins d'un jour.
     *
     * @return list<MastodonPublication>
     */
    #[AsTwigFunction('mastodon_publications')]
    public function publications(): array
    {
        $user = $this->security->getUser();

        return $user instanceof MastodonUser ? $this->publications->unfinishedOf($user, $this->clock->now()->modify('-1 day')) : [];
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
