<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notifications des comptes locaux (J'aime et commentaires reçus).
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261120090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notifications';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('notifications');
        $table->addColumn('id', 'uuid');
        $table->addColumn('read_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('type', Types::STRING, ['length' => 20]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('recipient_id', 'uuid');
        $table->addColumn('actor_id', 'uuid');
        $table->addColumn('post_id', 'uuid');
        $table->addColumn('comment_id', 'uuid', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['recipient_id', 'created_at'], 'idx_notifications_recipient_created');
        $table->addIndex(['recipient_id'], 'IDX_6000B0D3E92F8F78');
        $table->addIndex(['actor_id'], 'IDX_6000B0D310DAF24A');
        $table->addIndex(['post_id'], 'IDX_6000B0D34B89032C');
        $table->addIndex(['comment_id'], 'IDX_6000B0D3F8697D13');
        $table->addForeignKeyConstraint('accounts', ['recipient_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_6000B0D3E92F8F78');
        $table->addForeignKeyConstraint('accounts', ['actor_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_6000B0D310DAF24A');
        $table->addForeignKeyConstraint('posts', ['post_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_6000B0D34B89032C');
        $table->addForeignKeyConstraint('comments', ['comment_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_6000B0D3F8697D13');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('notifications');
    }
}
