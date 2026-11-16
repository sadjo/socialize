<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Application Meta : celle de l'instance (service, INSTAGRAM_APP_ID et INSTAGRAM_APP_SECRET, facultatifs) ou celle
 * d'un compte (Réglages › Instagram, voir InstagramConnections::appFor()). Sans application, pas de connexion
 * possible ; le partage manuel reste disponible.
 */
final readonly class InstagramApp
{
    public function __construct(
        #[Autowire(env: 'INSTAGRAM_APP_ID')]
        public string $id,
        #[Autowire(env: 'INSTAGRAM_APP_SECRET')]
        #[\SensitiveParameter]
        public string $secret,
        /** Configuration « Facebook Login for Business » : connexion unifiée (applications récentes). */
        #[Autowire(env: 'default::INSTAGRAM_CONFIG_ID')]
        public ?string $configId = null,
    ) {
    }

    public function isUnified(): bool
    {
        return null !== $this->configId && '' !== trim($this->configId);
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->id) && '' !== trim($this->secret);
    }
}
