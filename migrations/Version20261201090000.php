<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Filtre d'affichage des médias dans les listes, choisi par chaque personne (léger par défaut). Écrite avec l'API
 * Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261201090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Filtre des médias par utilisateur';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('users')->addColumn('media_filter', Types::STRING, ['length' => 16, 'default' => 'light']);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('users')->dropColumn('media_filter');
    }
}
