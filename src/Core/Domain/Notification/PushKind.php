<?php

declare(strict_types=1);

namespace App\Core\Domain\Notification;

/**
 * Nature d'une notification envoyée sur les appareils (Web Push).
 */
enum PushKind: string
{
    case Post = 'post';
    case Story = 'story';
    case Like = 'like';
    case Comment = 'comment';
    case FollowRequest = 'follow_request';
}
