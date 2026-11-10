<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Musique des publications et des stories : morceaux libres d'Openverse copiés sur l'instance.
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261113220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Musique des publications et des stories';
    }

    public function up(Schema $schema): void
    {
        $tracks = $schema->createTable('music_tracks');
        $tracks->addColumn('id', 'uuid');
        $tracks->setPrimaryKey(['id']);
        $tracks->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $tracks->addColumn('openverse_id', Types::STRING, ['length' => 64]);
        $tracks->addColumn('title', Types::STRING, ['length' => 255]);
        $tracks->addColumn('artist', Types::STRING, ['length' => 255]);
        $tracks->addColumn('license', Types::STRING, ['length' => 30]);
        $tracks->addColumn('license_version', Types::STRING, ['length' => 10, 'notnull' => false]);
        $tracks->addColumn('license_url', Types::STRING, ['length' => 500, 'notnull' => false]);
        $tracks->addColumn('source_url', Types::STRING, ['length' => 500, 'notnull' => false]);
        $tracks->addColumn('provider', Types::STRING, ['length' => 50]);
        $tracks->addColumn('duration', Types::INTEGER);
        $tracks->addColumn('path', Types::STRING, ['length' => 255]);
        $tracks->addUniqueIndex(['openverse_id'], 'UNIQ_B1B5E9D7A28F2474');

        foreach (['posts' => '885DBAFA', 'stories' => '9C8B9D5F'] as $name => $suffix) {
            $table = $schema->getTable($name);
            $table->addColumn('music_id', 'uuid', ['notnull' => false]);
            $table->addColumn('music_start', Types::INTEGER, ['default' => 0]);
            $table->addIndex(['music_id'], 'IDX_'.$suffix.'399BBB13');
            $table->addForeignKeyConstraint('music_tracks', ['music_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_'.$suffix.'399BBB13');
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['posts' => '885DBAFA', 'stories' => '9C8B9D5F'] as $name => $suffix) {
            $table = $schema->getTable($name);
            $table->removeForeignKey('FK_'.$suffix.'399BBB13');
            $table->dropIndex('IDX_'.$suffix.'399BBB13');
            $table->dropColumn('music_start');
            $table->dropColumn('music_id');
        }
        $schema->dropTable('music_tracks');
    }
}
