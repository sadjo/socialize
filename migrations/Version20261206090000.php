<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Image de couverture choisie pour une story à la une (envoyée, ou venue d'Immich ou de Synology Photos).
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261206090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Couverture choisie des stories à la une';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('story_highlights')->addColumn('cover_path', Types::STRING, ['length' => 255, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('story_highlights')->dropColumn('cover_path');
    }
}
