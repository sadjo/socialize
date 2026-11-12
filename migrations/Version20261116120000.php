<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Connexion d'un compte à Synology Photos (session DSM chiffrée, jamais le mot de passe).
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261116120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Connexions Synology Photos';
    }

    public function up(Schema $schema): void
    {
        $connections = $schema->createTable('synology_photos_connections');
        $connections->addColumn('id', 'uuid');
        $connections->setPrimaryKey(['id']);
        $connections->addColumn('account_id', 'uuid');
        $connections->addColumn('server_url', Types::STRING, ['length' => 500]);
        $connections->addColumn('user_name', Types::STRING, ['length' => 200]);
        $connections->addColumn('session_encrypted', Types::TEXT);
        $connections->addColumn('connected_at', Types::DATETIME_IMMUTABLE);
        $connections->addColumn('expired_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $connections->addUniqueIndex(['account_id'], 'UNIQ_546E36DA9B6B5FBA');
        $connections->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_546E36DA9B6B5FBA');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('synology_photos_connections');
    }
}
