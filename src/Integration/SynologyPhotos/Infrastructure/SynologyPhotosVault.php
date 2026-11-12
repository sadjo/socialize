<?php

declare(strict_types=1);

namespace App\Integration\SynologyPhotos\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffre les sessions DSM au repos (libsodium), avec une clé dérivée d'APP_SECRET propre à Synology Photos.
 */
final readonly class SynologyPhotosVault
{
    private string $key;

    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        string $secret,
    ) {
        $this->key = sodium_crypto_generichash('socialize:synology-photos-sessions:'.$secret, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(#[\SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'v1:'.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $encrypted): string
    {
        $raw = str_starts_with($encrypted, 'v1:') ? base64_decode(substr($encrypted, 3), true) : false;
        $plain = false !== $raw && \strlen($raw) > \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            ? sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key)
            : false;
        if (false === $plain) {
            throw new \RuntimeException('Session Synology Photos illisible : APP_SECRET a-t-il changé ? Reconnectez Synology Photos dans les réglages.');
        }

        return $plain;
    }
}
