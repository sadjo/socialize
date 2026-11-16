<?php

declare(strict_types=1);

namespace App\Web\Security;

use App\Integration\Mastodon\Domain\MastodonUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Ce qu'une personne connectée avec Mastodon peut faire ici : lire (fils, profils, publications, stories), marquer
 * une story comme vue, aimer, commenter et suivre. Le reste (publier, réglages, notifications, import, administration) lui est fermé :
 * son compte se gère sur son serveur.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class MastodonAccess
{
    /** Pages fermées (préfixes de noms de routes). */
    private const array CLOSED = [
        'admin_', 'app_settings', 'app_compose', 'app_import', 'app_notification', 'app_push_', 'app_music_', 'app_immich_',
        'app_synology_photos_', 'app_story_new', 'app_story_manage', 'app_story_archive', 'app_post_edit', 'app_instagram_', 'app_post_instagram',
    ];

    /** Seules actions permises (routes en POST) : J'aime, commentaires et abonnements passent par son serveur. */
    private const array ACTIONS = [
        'app_story_seen', 'app_login', 'app_mastodon_login',
        'app_post_like', 'app_post_comment', 'app_comment_delete', 'app_profile_follow', 'app_profile_unfollow',
    ];

    public function __construct(
        private Security $security,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->security->getUser() instanceof MastodonUser) {
            return;
        }
        $request = $event->getRequest();
        $route = $request->attributes->getString('_route');
        $closed = array_any(self::CLOSED, static fn (string $prefix): bool => str_starts_with($route, $prefix))
            || (!$request->isMethodSafe() && !\in_array($route, self::ACTIONS, true));
        if ($closed) {
            throw new AccessDeniedException('Votre compte Mastodon se gère sur votre serveur : cette action n’est pas disponible ici.');
        }
    }
}
