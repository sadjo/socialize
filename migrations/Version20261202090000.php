<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Demandes d'abonnement validées par le compte lui-même (réglage du profil, désactivé par défaut). Écrite avec l'API
 * Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261202090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Validation des demandes d’abonnement';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('accounts')->addColumn('manually_approves_followers', Types::BOOLEAN, ['default' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('accounts')->dropColumn('manually_approves_followers');
    }
}
