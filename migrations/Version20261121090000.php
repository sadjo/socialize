<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Connexion unifiée de Meta (applications récentes) : identifiant de configuration de l'application d'un compte, et
 * connexions faites par ce chemin (API sur graph.facebook.com).
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261121090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Connexion unifiée de Meta pour Instagram';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('instagram_apps')->addColumn('config_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $schema->getTable('instagram_connections')->addColumn('unified_login', Types::BOOLEAN, ['default' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('instagram_apps')->dropColumn('config_id');
        $schema->getTable('instagram_connections')->dropColumn('unified_login');
    }
}
