<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Crosspost Instagram : compte Instagram relié (jeton chiffré, règles) et envois des publications et stories.
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261117100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crosspost Instagram : connexions et envois';
    }

    public function up(Schema $schema): void
    {
        $connections = $schema->createTable('instagram_connections');
        $connections->addColumn('id', 'uuid');
        $connections->setPrimaryKey(['id']);
        $connections->addColumn('account_id', 'uuid');
        $connections->addColumn('instagram_user_id', Types::STRING, ['length' => 64]);
        $connections->addColumn('username', Types::STRING, ['length' => 100]);
        $connections->addColumn('token_encrypted', Types::TEXT);
        $connections->addColumn('token_expires_at', Types::DATETIME_IMMUTABLE);
        $connections->addColumn('connected_at', Types::DATETIME_IMMUTABLE);
        $connections->addColumn('refreshed_at', Types::DATETIME_IMMUTABLE);
        $connections->addColumn('expired_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $connections->addColumn('auto_visibilities', Types::JSON);
        $connections->addColumn('auto_stories', Types::BOOLEAN, ['default' => false]);
        $connections->addUniqueIndex(['account_id'], 'UNIQ_EB1326989B6B5FBA');
        $connections->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EB1326989B6B5FBA');

        $crossposts = $schema->createTable('instagram_crossposts');
        $crossposts->addColumn('id', 'uuid');
        $crossposts->setPrimaryKey(['id']);
        $crossposts->addColumn('account_id', 'uuid');
        $crossposts->addColumn('post_id', 'uuid', ['notnull' => false]);
        $crossposts->addColumn('story_id', 'uuid', ['notnull' => false]);
        $crossposts->addColumn('status', Types::STRING, ['length' => 20]);
        $crossposts->addColumn('media_containers', Types::JSON);
        $crossposts->addColumn('container_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $crossposts->addColumn('instagram_media_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $crossposts->addColumn('permalink', Types::STRING, ['length' => 500, 'notnull' => false]);
        $crossposts->addColumn('error', Types::STRING, ['length' => 1000, 'notnull' => false]);
        $crossposts->addColumn('checks', Types::INTEGER);
        $crossposts->addColumn('files', Types::JSON);
        $crossposts->addColumn('published_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $crossposts->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $crossposts->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $crossposts->addIndex(['status'], 'idx_instagram_crossposts_status');
        $crossposts->addIndex(['account_id'], 'IDX_2B00D2899B6B5FBA');
        $crossposts->addIndex(['post_id'], 'IDX_2B00D2894B89032C');
        $crossposts->addIndex(['story_id'], 'IDX_2B00D289AA5D4036');
        $crossposts->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_2B00D2899B6B5FBA');
        $crossposts->addForeignKeyConstraint('posts', ['post_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_2B00D2894B89032C');
        $crossposts->addForeignKeyConstraint('stories', ['story_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_2B00D289AA5D4036');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('instagram_crossposts');
        $schema->dropTable('instagram_connections');
    }
}
