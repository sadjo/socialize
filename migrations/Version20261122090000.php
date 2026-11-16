<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Connexion Instagram : droit de supprimer les publications Instagram (connexion unifiée, instagram_manage_contents).
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261122090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppression des publications Instagram';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('instagram_connections')->addColumn('can_delete', Types::BOOLEAN, ['default' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('instagram_connections')->dropColumn('can_delete');
    }
}
