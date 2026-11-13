<?php

declare(strict_types=1);

namespace App\Core\Domain\Notification;

/**
 * Nature d'une notification. D'autres viendront (abonnements, mentions…), réglables par compte.
 */
enum NotificationType: string
{
    case Like = 'like';
    case Comment = 'comment';
}
