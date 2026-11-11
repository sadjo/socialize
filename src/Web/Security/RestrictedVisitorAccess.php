<?php

declare(strict_types=1);

namespace App\Web\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Instance pas encore ouverte au public (temporaire, HOME_LOGIN_ONLY) : les visiteurs non connectés n'ont accès
 * ni aux fils publics, ni à la recherche, ni à l'inscription, et sont renvoyés vers l'accueil, qui porte le formulaire
 * de connexion. La page /connexion n'est plus affichée mais reçoit toujours le formulaire (POST, géré par le pare-feu ;
 * une erreur y renvoie, puis vers l'accueil, qui l'affiche). Profils et publications publics restent consultables.
 */
final readonly class RestrictedVisitorAccess
{
    /** Pages fermées aux visiteurs. */
    public const array ROUTES = ['app_feed_local', 'app_feed_federated', 'app_search', 'app_register', 'app_login'];

    public function __construct(
        private CurrentAccount $currentAccount,
        private UrlGeneratorInterface $urls,
        #[Autowire(env: 'bool:HOME_LOGIN_ONLY')]
        private bool $enabled = false,
    ) {
    }

    // Après le pare-feu (priorité 8) : la session de l'utilisateur est connue.
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }
        if (\in_array($event->getRequest()->attributes->get('_route'), self::ROUTES, true) && !$this->currentAccount->get() instanceof \App\Core\Domain\Account\Account) {
            $event->setResponse(new RedirectResponse($this->urls->generate('app_home')));
        }
    }
}
