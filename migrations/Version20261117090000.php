<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Réseaux vers lesquels une publication ou une story est aussi envoyée (crosspost Instagram).
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261117090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crosspost demandé sur les publications et les stories';
    }

    public function up(Schema $schema): void
    {
        foreach (['posts', 'stories'] as $table) {
            $schema->getTable($table)->addColumn('crosspost_targets', Types::JSON, ['notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['posts', 'stories'] as $table) {
            $schema->getTable($table)->dropColumn('crosspost_targets');
        }
    }
}
