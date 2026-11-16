<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Connexion avec un compte Mastodon : application OAuth par serveur, et personnes connectées avec leur compte.
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261124090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Connexion avec un compte Mastodon';
    }

    public function up(Schema $schema): void
    {
        $apps = $schema->createTable('mastodon_apps');
        $apps->addColumn('id', 'uuid');
        $apps->addColumn('domain', Types::STRING, ['length' => 253]);
        $apps->addColumn('client_id', Types::STRING, ['length' => 255]);
        $apps->addColumn('client_secret_encrypted', Types::TEXT);
        $apps->addColumn('redirect_uri', Types::STRING, ['length' => 500]);
        $apps->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $apps->setPrimaryKey(['id']);
        $apps->addUniqueIndex(['domain'], 'UNIQ_7E5E869BA7A91E0B');

        $users = $schema->createTable('mastodon_users');
        $users->addColumn('id', 'uuid');
        $users->addColumn('identifier', Types::STRING, ['length' => 300]);
        $users->addColumn('token_encrypted', Types::TEXT, ['notnull' => false]);
        $users->addColumn('scopes', Types::STRING, ['length' => 255]);
        $users->addColumn('roles', Types::JSON);
        $users->addColumn('last_login_at', Types::DATETIME_IMMUTABLE);
        $users->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $users->addColumn('account_id', 'uuid');
        $users->setPrimaryKey(['id']);
        $users->addUniqueIndex(['identifier'], 'UNIQ_F88E1537772E836A');
        $users->addUniqueIndex(['account_id'], 'UNIQ_F88E15379B6B5FBA');
        $users->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_F88E15379B6B5FBA');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('mastodon_users');
        $schema->dropTable('mastodon_apps');
    }
}
