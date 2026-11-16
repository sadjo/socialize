<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notifications sur les appareils (Web Push) : appareils abonnés et préférences de chaque compte.
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261123090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notifications Web Push';
    }

    public function up(Schema $schema): void
    {
        $push = $schema->createTable('push_subscriptions');
        $push->addColumn('id', 'uuid');
        $push->addColumn('endpoint_hash', Types::STRING, ['length' => 64]);
        $push->addColumn('endpoint', Types::TEXT);
        $push->addColumn('public_key', Types::STRING, ['length' => 200]);
        $push->addColumn('auth_token', Types::STRING, ['length' => 100]);
        $push->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $push->addColumn('account_id', 'uuid');
        $push->setPrimaryKey(['id']);
        $push->addUniqueIndex(['endpoint_hash'], 'UNIQ_3FEC449D867498CF');
        $push->addIndex(['account_id'], 'IDX_3FEC449D9B6B5FBA');
        $push->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_3FEC449D9B6B5FBA');

        $prefs = $schema->createTable('notification_preferences');
        $prefs->addColumn('id', 'uuid');
        foreach (['posts', 'stories', 'likes', 'comments'] as $column) {
            $prefs->addColumn($column, Types::BOOLEAN, ['default' => true]);
        }
        $prefs->addColumn('account_id', 'uuid');
        $prefs->setPrimaryKey(['id']);
        $prefs->addUniqueIndex(['account_id'], 'UNIQ_3CAA95B49B6B5FBA');
        $prefs->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_3CAA95B49B6B5FBA');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('notification_preferences');
        $schema->dropTable('push_subscriptions');
    }
}
