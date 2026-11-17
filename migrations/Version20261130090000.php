<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Thème de l'interface choisi par chaque personne. Écrite avec l'API Schema pour rester portable entre SQLite et
 * MySQL / MariaDB.
 */
final class Version20261130090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Thème de l’interface par utilisateur';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('users')->addColumn('theme', Types::STRING, ['length' => 16, 'default' => 'auto']);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('users')->dropColumn('theme');
    }
}
