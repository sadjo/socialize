<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * SQLite n'applique les clés étrangères (ON DELETE CASCADE…) que si on l'active à chaque connexion.
 * Le middleware de DBAL ne regarde pas le pilote (MySQL / MariaDB refusent la commande PRAGMA),
 * et le pilote reçu ici peut déjà être enveloppé : on se fie au pilote déclaré dans les paramètres.
 */
// Priorité haute : appliqué au plus près du pilote, avant tout middleware qui ouvrirait une transaction
// (SQLite ignore PRAGMA foreign_keys dans une transaction).
#[AsMiddleware(priority: 200)]
final class SqliteForeignKeys implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): Connection
            {
                $connection = parent::connect($params);
                if (str_contains((string) ($params['driver'] ?? ''), 'sqlite')) {
                    $connection->exec('PRAGMA foreign_keys = ON');
                }

                return $connection;
            }
        };
    }
}
