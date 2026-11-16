<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffre les jetons et secrets Mastodon au repos (libsodium), avec une clé dérivée d'APP_SECRET propre à Mastodon.
 */
final readonly class MastodonVault
{
    private string $key;

    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        #[\SensitiveParameter]
        string $secret,
    ) {
        $this->key = sodium_crypto_generichash('socialize:mastodon:'.$secret, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
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
            throw new \RuntimeException('Jeton Mastodon illisible : APP_SECRET a-t-il changé ? Reconnectez-vous avec Mastodon.');
        }

        return $plain;
    }
}
