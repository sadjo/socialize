<?php

declare(strict_types=1);

namespace App\Web\Security;

use App\Core\Domain\Account\User;
use App\Integration\Mastodon\Domain\MastodonUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Ce qu'une personne connectée avec Mastodon peut faire ici : lire (fils, profils, publications, stories), marquer
 * une story comme vue, aimer, commenter, suivre, accepter, refuser ou retirer ses abonnés, lire ses notifications,
 * choisir l'apparence, publier sur son compte Mastodon, et y importer son archive Instagram si un administrateur le lui
 * a permis (pages d'import et onglet Instagram des réglages). Le reste (publier ici, autres réglages, notifications sur l'appareil, import, administration) lui est
 * fermé : son compte se gère sur son serveur.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class MastodonAccess
{
    /** Pages fermées (préfixes de noms de routes). */
    private const array CLOSED = [
        'admin_', 'app_settings', 'app_compose', 'app_import', 'app_push_', 'app_music_', 'app_immich_',
        'app_synology_photos_', 'app_story_new', 'app_story_manage', 'app_story_archive', 'app_post_edit', 'app_instagram_', 'app_post_instagram',
    ];

    /**
     * Seules actions permises (routes en POST) : J'aime, commentaires, abonnements et abonnés passent par son serveur ;
     * ses notifications se lisent, se marquent et se suppriment ici.
     */
    private const array ACTIONS = [
        'app_story_seen', 'app_login', 'app_mastodon_login',
        'app_post_like', 'app_post_comment', 'app_comment_delete', 'app_profile_follow', 'app_profile_unfollow',
        'app_follow_request_accept', 'app_profile_remove_follower',
        'app_notification_open', 'app_notification_toggle', 'app_notification_delete',
        'app_notifications_read_all', 'app_notifications_delete_all',
        'app_mastodon_compose', 'app_post_delete', 'app_settings_appearance',
    ];

    /** Pages ouvertes malgré leur préfixe fermé : l'apparence se choisit ici. */
    private const array OPEN = ['app_settings_appearance'];

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
        // Import Instagram vers son compte Mastodon, si un administrateur le lui a permis : les pages d'import, et l'onglet
        // Instagram des réglages qui y mène (en lecture : il n'y voit que l'import).
        if ($this->security->isGranted(User::ROLE_INSTAGRAM_IMPORTER)
            && (str_starts_with($route, 'app_import') || ('app_settings_instagram' === $route && $request->isMethodSafe()))) {
            return;
        }
        $closed = (!\in_array($route, self::OPEN, true) && array_any(self::CLOSED, static fn (string $prefix): bool => str_starts_with($route, $prefix)))
            || (!$request->isMethodSafe() && !\in_array($route, self::ACTIONS, true));
        if ($closed) {
            throw new AccessDeniedException('Votre compte Mastodon se gère sur votre serveur : cette action n’est pas disponible ici.');
        }
    }
}
