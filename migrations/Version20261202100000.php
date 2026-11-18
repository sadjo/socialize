<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notification sur les appareils des demandes d'abonnement à valider (activée par défaut). Écrite avec l'API Schema
 * pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261202100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification push des demandes d’abonnement';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('notification_preferences')->addColumn('follow_requests', Types::BOOLEAN, ['default' => true]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('notification_preferences')->dropColumn('follow_requests');
    }
}
