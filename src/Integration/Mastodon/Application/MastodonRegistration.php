<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

/**
 * Conditions d'inscription d'un serveur Mastodon, montrées sur l'écran de création de compte.
 */
final readonly class MastodonRegistration
{
    /**
     * @param list<string> $rules règles du serveur, à accepter
     */
    public function __construct(
        public string $domain,
        public bool $open,
        public bool $approval,
        public ?int $minAge,
        public array $rules,
        public ?string $message,
    ) {
    }

    /**
     * Page d'inscription du serveur lui-même (si ses inscriptions par application sont fermées).
     */
    public function signUpUrl(): string
    {
        return 'https://'.$this->domain.'/auth/sign_up';
    }

    /**
     * Page « À propos » du serveur : règles, conditions d'utilisation et politique de confidentialité.
     */
    public function aboutUrl(): string
    {
        return 'https://'.$this->domain.'/about';
    }
}
