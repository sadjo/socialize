<?php

declare(strict_types=1);

namespace App\Core\Domain\Notification;

/**
 * Nature d'une notification. D'autres viendront (abonnements, mentions…).
 */
enum NotificationType: string
{
    case Like = 'like';
    case Comment = 'comment';
    /** Nouveau compte (inscription, ou première connexion avec Mastodon), pour les administrateurs. */
    case Signup = 'signup';
}
