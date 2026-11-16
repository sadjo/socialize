<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Publications envoyées sur un compte Mastodon depuis Socialize (suivi de l'envoi).
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261125090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Publications envoyées sur un compte Mastodon';
    }

    public function up(Schema $schema): void
    {
        $publications = $schema->createTable('mastodon_publications');
        $publications->addColumn('id', 'uuid');
        $publications->addColumn('state', Types::STRING, ['length' => 20]);
        $publications->addColumn('error', Types::STRING, ['length' => 500, 'notnull' => false]);
        $publications->addColumn('status_uri', Types::STRING, ['length' => 500, 'notnull' => false]);
        $publications->addColumn('excerpt', Types::STRING, ['length' => 200]);
        $publications->addColumn('media_count', Types::INTEGER);
        $publications->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $publications->addColumn('user_id', 'uuid');
        $publications->setPrimaryKey(['id']);
        $publications->addIndex(['created_at'], 'IDX_B7776BA88B8E8428');
        $publications->addIndex(['user_id'], 'IDX_B7776BA8A76ED395');
        $publications->addForeignKeyConstraint('mastodon_users', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_B7776BA8A76ED395');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('mastodon_publications');
    }
}
