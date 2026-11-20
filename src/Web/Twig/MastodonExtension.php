<?php

declare(strict_types=1);

namespace App\Web\Twig;

use App\Integration\Mastodon\Application\MastodonLogin;
use App\Integration\Mastodon\Application\MastodonLoginPolicy;
use App\Integration\Mastodon\Application\MastodonSignup;
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
        private MastodonSignup $signup,
    ) {
    }

    /**
     * Domaine d'un serveur Mastodon indiqué par la personne (voir MastodonLogin::domainOf()), ou null.
     */
    #[AsTwigFunction('mastodon_domain')]
    public function domain(string $server): ?string
    {
        return MastodonLogin::domainOf($server);
    }

    /**
     * Serveur où créer un compte Mastodon depuis l'écran de connexion (MASTODON_SIGNUP), ou null.
     */
    #[AsTwigFunction('mastodon_signup_server')]
    public function signupServer(): ?string
    {
        return $this->signup->server();
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
     * Boutons et champ de la connexion Mastodon (voir MastodonLoginPolicy::choices()) ; null si elle est fermée.
     *
     * @return ?array{buttons: list<string>, field: ?list<string>}
     */
    #[AsTwigFunction('mastodon_login')]
    public function login(): ?array
    {
        return $this->policy->choices();
    }
}
