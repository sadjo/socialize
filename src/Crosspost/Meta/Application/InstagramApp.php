<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Application Meta de l'instance (INSTAGRAM_APP_ID, INSTAGRAM_APP_SECRET). Sans elle, le crosspost Instagram est
 * absent ; le partage manuel reste possible.
 */
final readonly class InstagramApp
{
    public function __construct(
        #[Autowire(env: 'INSTAGRAM_APP_ID')]
        public string $id,
        #[Autowire(env: 'INSTAGRAM_APP_SECRET')]
        #[\SensitiveParameter]
        public string $secret,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->id) && '' !== trim($this->secret);
    }
}
