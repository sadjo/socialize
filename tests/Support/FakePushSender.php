<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Core\Application\Notification\PushSender;
use App\Core\Domain\Notification\PushSubscription;

/**
 * Faux envoi Web Push pour les tests : garde les notifications « envoyées » ; un appareil dont l'adresse contient
 * « perime » répond que l'abonnement n'existe plus.
 */
final class FakePushSender implements PushSender
{
    /** @var list<array{endpoint: string, payload: array{title: string, body: string, url: string, tag: string}}> */
    public static array $sent = [];

    public function send(PushSubscription $subscription, array $payload): bool
    {
        if (str_contains($subscription->getEndpoint(), 'perime')) {
            return false;
        }
        self::$sent[] = ['endpoint' => $subscription->getEndpoint(), 'payload' => $payload];

        return true;
    }

    public function publicKey(): string
    {
        return 'BCleDeTestVapid';
    }
}
