<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Jalon 6 : connexion des comptes à Immich (clé API chiffrée).
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261113200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Connexions Immich';
    }

    public function up(Schema $schema): void
    {
        $connections = $schema->createTable('immich_connections');
        $connections->addColumn('id', 'uuid');
        $connections->setPrimaryKey(['id']);
        $connections->addColumn('account_id', 'uuid');
        $connections->addColumn('server_url', Types::STRING, ['length' => 500]);
        $connections->addColumn('api_key_encrypted', Types::TEXT);
        $connections->addColumn('immich_user', Types::STRING, ['length' => 200, 'notnull' => false]);
        $connections->addColumn('server_version', Types::STRING, ['length' => 50, 'notnull' => false]);
        $connections->addColumn('checked_at', Types::DATETIME_IMMUTABLE);
        $connections->addColumn('add_to_album', Types::BOOLEAN, ['default' => false]);
        $connections->addColumn('album_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $connections->addUniqueIndex(['account_id'], 'UNIQ_231D5DFB9B6B5FBA');
        $connections->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_231D5DFB9B6B5FBA');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('immich_connections');
    }
}
