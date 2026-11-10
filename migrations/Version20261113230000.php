<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Musiques favorites des comptes (fiches Openverse, sans fichier).
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261113230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Musiques favorites';
    }

    public function up(Schema $schema): void
    {
        $favorites = $schema->createTable('music_favorites');
        $favorites->addColumn('id', 'uuid');
        $favorites->setPrimaryKey(['id']);
        $favorites->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $favorites->addColumn('account_id', 'uuid');
        $favorites->addColumn('openverse_id', Types::STRING, ['length' => 64]);
        $favorites->addColumn('title', Types::STRING, ['length' => 255]);
        $favorites->addColumn('artist', Types::STRING, ['length' => 255]);
        $favorites->addColumn('license', Types::STRING, ['length' => 30]);
        $favorites->addColumn('license_version', Types::STRING, ['length' => 10, 'notnull' => false]);
        $favorites->addColumn('provider', Types::STRING, ['length' => 50]);
        $favorites->addColumn('duration', Types::INTEGER);
        $favorites->addColumn('genres', Types::JSON);
        $favorites->addColumn('has_thumbnail', Types::BOOLEAN, ['default' => false]);
        $favorites->addUniqueIndex(['account_id', 'openverse_id'], 'uniq_music_favorites_account_track');
        $favorites->addIndex(['account_id', 'created_at'], 'idx_music_favorites_account_created');
        $favorites->addIndex(['account_id'], 'IDX_36A3F6549B6B5FBA');
        $favorites->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_36A3F6549B6B5FBA');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('music_favorites');
    }
}
