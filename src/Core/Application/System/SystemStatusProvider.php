<?php

declare(strict_types=1);

namespace App\Core\Application\System;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\HttpKernel\Kernel;

final readonly class SystemStatusProvider
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function current(bool $workerMode = false): SystemStatus
    {
        return new SystemStatus(
            phpVersion: \PHP_VERSION,
            symfonyVersion: Kernel::VERSION,
            databasePlatform: $this->platformName(),
            databaseReachable: $this->isDatabaseReachable(),
            workerMode: $workerMode,
        );
    }

    private function isDatabaseReachable(): bool
    {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();

            return true;
        } catch (DbalException) {
            return false;
        }
    }

    private function platformName(): string
    {
        try {
            $platform = $this->connection->getDatabasePlatform();
        } catch (DbalException) {
            return 'inconnue';
        }

        return match (true) {
            $platform instanceof SQLitePlatform => 'SQLite',
            $platform instanceof MariaDBPlatform => 'MariaDB',
            $platform instanceof AbstractMySQLPlatform => 'MySQL',
            default => new \ReflectionClass($platform)->getShortName(),
        };
    }
}
