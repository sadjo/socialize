<?php

declare(strict_types=1);

namespace App\Web\Http;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;

/**
 * Libère la session des actions marquées #[ReleaseSession], juste avant leur exécution. Le pare-feu est « lazy » :
 * il ne lit l'utilisateur dans la session qu'au premier besoin. L'utilisateur est donc lu ici, puis la session est
 * enregistrée et fermée (l'action ne l'écrit pas).
 */
final readonly class ReleaseSessionListener
{
    public function __construct(
        private Security $security,
    ) {
    }

    #[AsEventListener]
    public function __invoke(ControllerArgumentsEvent $event): void
    {
        $request = $event->getRequest();
        if ([] === $event->getAttributes(ReleaseSession::class) || !$request->hasSession()) {
            return;
        }
        $this->security->getUser();
        if ($request->getSession()->isStarted()) {
            $request->getSession()->save();
        }
    }
}
