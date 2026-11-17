<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notifications sans publication (nouveau compte, pour les administrateurs). Écrite avec l'API Schema pour rester
 * portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261129090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notifications sans publication';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('notifications')->getColumn('post_id')->setNotnull(false);
    }

    public function down(Schema $schema): void
    {
        // Retour en arrière : supprimer d'abord les notifications de nouveaux comptes (post_id vide).
        $schema->getTable('notifications')->getColumn('post_id')->setNotnull(true);
    }
}
