<?php

declare(strict_types=1);

namespace App\Core\Application\System;

/**
 * État de l'instance, affiché sur la page d'accueil et renvoyé par /healthz.
 */
final readonly class SystemStatus
{
    public function __construct(
        public string $phpVersion,
        public string $symfonyVersion,
        public string $databasePlatform,
        public bool $databaseReachable,
        public bool $workerMode,
    ) {
    }

    public function isHealthy(): bool
    {
        return $this->databaseReachable;
    }

    /**
     * @return array{status: string, php: string, symfony: string, database: array{platform: string, reachable: bool}, worker_mode: bool}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->isHealthy() ? 'ok' : 'degraded',
            'php' => $this->phpVersion,
            'symfony' => $this->symfonyVersion,
            'database' => [
                'platform' => $this->databasePlatform,
                'reachable' => $this->databaseReachable,
            ],
            'worker_mode' => $this->workerMode,
        ];
    }
}
