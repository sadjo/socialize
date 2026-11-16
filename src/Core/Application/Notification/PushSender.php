<?php

declare(strict_types=1);

namespace App\Core\Application\Notification;

use App\Core\Domain\Notification\PushSubscription;

/**
 * Envoi d'une notification à un appareil (Web Push). Rend faux quand l'abonnement n'existe plus (à oublier).
 */
interface PushSender
{
    /**
     * @param array{title: string, body: string, url: string, tag: string} $payload
     */
    public function send(PushSubscription $subscription, array $payload): bool;

    /** Clé publique VAPID, donnée aux navigateurs pour s'abonner. */
    public function publicKey(): string;
}
