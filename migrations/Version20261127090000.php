<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Image de couverture d'un compte : fichier déposé ici, ou adresse sur l'instance d'un compte distant. Écrite avec
 * l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261127090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Image de couverture des comptes';
    }

    public function up(Schema $schema): void
    {
        $accounts = $schema->getTable('accounts');
        $accounts->addColumn('cover_path', Types::STRING, ['length' => 255, 'notnull' => false]);
        $accounts->addColumn('cover_url', Types::STRING, ['length' => 500, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $accounts = $schema->getTable('accounts');
        $accounts->dropColumn('cover_path');
        $accounts->dropColumn('cover_url');
    }
}
