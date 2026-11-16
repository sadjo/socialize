<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Push;

use App\Core\Infrastructure\Doctrine\SettingRepository;
use Minishlink\WebPush\VAPID;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Clés VAPID de l'instance (identifient le serveur auprès des services de notification des navigateurs). Créées au
 * premier besoin et gardées en base, la clé privée chiffrée avec une clé dérivée d'APP_SECRET : rien à configurer.
 */
final class VapidKeys
{
    private const string PUBLIC = 'push.vapid_public';
    private const string PRIVATE = 'push.vapid_private';

    private readonly string $key;

    /** @var array{publicKey: string, privateKey: string}|null */
    private ?array $keys = null;

    public function __construct(
        private readonly SettingRepository $settings,
        #[Autowire(param: 'kernel.secret')]
        #[\SensitiveParameter]
        string $secret,
    ) {
        $this->key = sodium_crypto_generichash('socialize:vapid:'.$secret, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /**
     * @return array{publicKey: string, privateKey: string}
     */
    public function get(): array
    {
        if (null !== $this->keys) {
            return $this->keys;
        }
        $public = $this->settings->get(self::PUBLIC);
        $private = $this->settings->get(self::PRIVATE);
        if (null !== $public && null !== $private) {
            return $this->keys = ['publicKey' => $public, 'privateKey' => $this->decrypt($private)];
        }
        $created = VAPID::createVapidKeys();
        $public = $created['publicKey'] ?? null;
        $private = $created['privateKey'] ?? null;
        if (!\is_string($public) || !\is_string($private)) {
            throw new \RuntimeException('Création des clés VAPID impossible.');
        }
        $keys = ['publicKey' => $public, 'privateKey' => $private];
        $this->settings->set(self::PUBLIC, $keys['publicKey']);
        $this->settings->set(self::PRIVATE, $this->encrypt($keys['privateKey']));

        return $this->keys = $keys;
    }

    private function encrypt(#[\SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'v1:'.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    private function decrypt(string $encrypted): string
    {
        $raw = base64_decode(substr($encrypted, 3), true);
        $plain = false !== $raw ? sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key) : false;

        return false !== $plain ? $plain : throw new \RuntimeException('Clé VAPID illisible (APP_SECRET changé ?).');
    }
}
