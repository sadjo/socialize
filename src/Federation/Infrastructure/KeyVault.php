<?php

declare(strict_types=1);

namespace App\Federation\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffre les clés privées ActivityPub au repos (libsodium), avec une clé dérivée d'APP_SECRET.
 */
final readonly class KeyVault
{
    private string $key;

    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        string $secret,
    ) {
        $this->key = sodium_crypto_generichash('socialize:activitypub-keys:'.$secret, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'v1:'.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $encrypted): string
    {
        if (!str_starts_with($encrypted, 'v1:')) {
            throw new \RuntimeException('Format de clé chiffrée inconnu.');
        }
        $raw = base64_decode(substr($encrypted, 3), true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Clé chiffrée illisible.');
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key);
        if (false === $plain) {
            throw new \RuntimeException('Impossible de déchiffrer la clé : APP_SECRET a-t-il changé ?');
        }

        return $plain;
    }

    /**
     * @return array{public: string, private: string}
     */
    public static function generateRsaKeyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        if (false === $key || !openssl_pkey_export($key, $private)) {
            throw new \RuntimeException('Impossible de générer une paire de clés RSA.');
        }
        $details = openssl_pkey_get_details($key);
        if (false === $details || !\is_string($details['key'] ?? null) || !\is_string($private)) {
            throw new \RuntimeException('Impossible de lire la clé publique générée.');
        }

        return ['public' => $details['key'], 'private' => $private];
    }
}
