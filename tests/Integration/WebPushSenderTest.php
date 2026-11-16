<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Domain\Notification\PushSubscription;
use App\Core\Infrastructure\Push\VapidKeys;
use App\Core\Infrastructure\Push\WebPushSender;
use App\Tests\AppWebTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Envoi Web Push réel (bibliothèque, chiffrement, signature VAPID) vers un faux service de notification.
 */
final class WebPushSenderTest extends AppWebTestCase
{
    public function testANotificationIsEncryptedSignedAndSent(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? [], 'body' => $options['body'] ?? ''];

            return new MockResponse('', ['http_code' => str_contains($url, 'perime') ? 410 : 201]);
        });
        $keys = new VapidKeys(self::getContainer()->get(\App\Core\Infrastructure\Doctrine\SettingRepository::class), 'secret-de-test');
        $sender = new WebPushSender($keys, new NullLogger(), $http, 'https://socialize.example');
        $account = $this->register('camille')->getAccount();
        $device = new PushSubscription($account, 'https://web.push.apple.com/QGh-jeton', 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'tBHItJI5svbpez7KI4CCXg', new \DateTimeImmutable());

        self::assertTrue($sender->send($device, ['title' => 'Hugo a aimé votre publication', 'body' => 'Lac', 'url' => '/p/1Ch1', 'tag' => 'like-1Ch1']));
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://web.push.apple.com/QGh-jeton', $requests[0]['url']);
        $headers = implode("\n", array_map(static fn (mixed $h): string => \is_string($h) ? $h : '', (array) $requests[0]['headers']));
        self::assertStringContainsStringIgnoringCase('content-encoding: aes128gcm', $headers);
        self::assertStringContainsStringIgnoringCase('authorization: vapid t=', $headers, 'Signé par les clés VAPID.');
        self::assertStringContainsString('k='.$keys->get()['publicKey'], $headers);
        self::assertStringContainsStringIgnoringCase('ttl: 86400', $headers);
        self::assertNotSame('', $requests[0]['body'], 'Message chiffré.');
        self::assertStringNotContainsString('Hugo', \is_string($requests[0]['body']) ? $requests[0]['body'] : '', 'Le texte ne circule pas en clair.');

        $expired = new PushSubscription($account, 'https://web.push.apple.com/perime', 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'tBHItJI5svbpez7KI4CCXg', new \DateTimeImmutable());
        self::assertFalse($sender->send($expired, ['title' => 't', 'body' => '', 'url' => '/', 'tag' => 'x']), 'Abonnement disparu (410) : à oublier.');
    }
}
