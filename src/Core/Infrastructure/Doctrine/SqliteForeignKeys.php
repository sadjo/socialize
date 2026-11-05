<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * SQLite n'applique les clés étrangères (ON DELETE CASCADE…) que si on l'active à chaque connexion.
 * Le middleware de DBAL ne regarde pas le pilote (MySQL / MariaDB refusent la commande PRAGMA),
 * et le pilote reçu ici peut déjà être enveloppé : on se fie au pilote déclaré dans les paramètres.
 *
 * IMPORTANT : les migrations doivent tourner avec SQLITE_FOREIGN_KEYS=0. Pour modifier une colonne,
 * Doctrine reconstruit la table (copie, suppression, renommage) ; avec les clés étrangères actives,
 * la suppression de l'ancienne table déclencherait ON DELETE CASCADE et viderait les tables liées.
 */
// Priorité haute : appliqué au plus près du pilote, avant tout middleware qui ouvrirait une transaction
// (SQLite ignore PRAGMA foreign_keys dans une transaction).
#[AsMiddleware(priority: 200)]
final readonly class SqliteForeignKeys implements Middleware
{
    public function __construct(
        #[Autowire(env: 'bool:SQLITE_FOREIGN_KEYS')]
        private bool $enabled = true,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        if (!$this->enabled) {
            return $driver;
        }

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
