<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Push;

use App\Core\Application\Notification\PushSender;
use App\Core\Domain\Notification\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envoi Web Push (bibliothèque minishlink/web-push) : message chiffré pour l'appareil, signé par les clés VAPID.
 */
#[AsAlias(PushSender::class)]
final readonly class WebPushSender implements PushSender
{
    public function __construct(
        private VapidKeys $keys,
        private LoggerInterface $logger,
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'DEFAULT_URI')]
        private string $defaultUri,
    ) {
    }

    public function send(PushSubscription $subscription, array $payload): bool
    {
        $keys = $this->keys->get();
        // Client HTTP de Symfony (PSR-18), donné explicitement : la bibliothèque n'a pas à en chercher un.
        $client = new Psr18Client($this->httpClient);
        $webPush = new WebPush(['VAPID' => ['subject' => $this->defaultUri, 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']]], client: $client, requestFactory: $client, streamFactory: $client);
        $report = $webPush->sendOneNotification(
            Subscription::create(['endpoint' => $subscription->getEndpoint(), 'publicKey' => $subscription->getPublicKey(), 'authToken' => $subscription->getAuthToken(), 'contentEncoding' => 'aes128gcm']),
            json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            ['TTL' => 86400, 'urgency' => 'normal', 'topic' => substr(preg_replace('/[^A-Za-z0-9_-]/', '', $payload['tag']) ?? '', 0, 32)],
        );
        if ($report->isSubscriptionExpired()) {
            return false;
        }
        if (!$report->isSuccess()) {
            $this->logger->warning('Notification non envoyée', ['raison' => $report->getReason()]);
        }

        return true;
    }

    public function publicKey(): string
    {
        return $this->keys->get()['publicKey'];
    }
}
