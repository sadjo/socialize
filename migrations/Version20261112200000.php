<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Jalon 4 : fédération ActivityPub (comptes distants, clés, identifiants ActivityPub,
 * médias distants, réglages de l'instance). Écrite avec l'API Schema (SQLite et MySQL / MariaDB).
 */
final class Version20261112200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fédération ActivityPub';
    }

    public function up(Schema $schema): void
    {
        $accounts = $schema->getTable('accounts');
        $accounts->getColumn('username')->setLength(100);
        $accounts->getColumn('display_name')->setLength(200);
        foreach (['ap_uri', 'inbox_url', 'shared_inbox_url', 'outbox_url', 'profile_url', 'avatar_url'] as $column) {
            $accounts->addColumn($column, Types::STRING, ['length' => 500, 'notnull' => false]);
        }
        $accounts->addColumn('public_key_pem', Types::TEXT, ['notnull' => false]);
        $accounts->addColumn('private_key_encrypted', Types::TEXT, ['notnull' => false]);
        $accounts->addColumn('fetched_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $accounts->addUniqueIndex(['ap_uri'], 'UNIQ_CAC89EAC638BEA54');

        $posts = $schema->getTable('posts');
        $posts->addColumn('ap_uri', Types::STRING, ['length' => 500, 'notnull' => false]);
        $posts->addColumn('url', Types::STRING, ['length' => 500, 'notnull' => false]);
        $posts->addUniqueIndex(['ap_uri'], 'UNIQ_885DBAFA638BEA54');

        $comments = $schema->getTable('comments');
        $comments->addColumn('ap_uri', Types::STRING, ['length' => 500, 'notnull' => false]);
        $comments->addUniqueIndex(['ap_uri'], 'UNIQ_5F9E962A638BEA54');

        $schema->getTable('likes')->addColumn('ap_uri', Types::STRING, ['length' => 500, 'notnull' => false]);
        $schema->getTable('follows')->addColumn('ap_uri', Types::STRING, ['length' => 500, 'notnull' => false]);
        $schema->getTable('media_attachments')->addColumn('remote_url', Types::TEXT, ['notnull' => false]);

        $settings = $schema->createTable('instance_settings');
        $settings->addColumn('name', Types::STRING, ['length' => 100]);
        $settings->addColumn('value', Types::TEXT);
        $settings->setPrimaryKey(['name']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('instance_settings');
        $schema->getTable('media_attachments')->dropColumn('remote_url');
        $schema->getTable('follows')->dropColumn('ap_uri');
        $schema->getTable('likes')->dropColumn('ap_uri');

        $comments = $schema->getTable('comments');
        $comments->dropIndex('UNIQ_5F9E962A638BEA54');
        $comments->dropColumn('ap_uri');

        $posts = $schema->getTable('posts');
        $posts->dropIndex('UNIQ_885DBAFA638BEA54');
        $posts->dropColumn('ap_uri');
        $posts->dropColumn('url');

        $accounts = $schema->getTable('accounts');
        $accounts->dropIndex('UNIQ_CAC89EAC638BEA54');
        foreach (['ap_uri', 'inbox_url', 'shared_inbox_url', 'outbox_url', 'profile_url', 'avatar_url', 'public_key_pem', 'private_key_encrypted', 'fetched_at'] as $column) {
            $accounts->dropColumn($column);
        }
        $accounts->getColumn('username')->setLength(30);
        $accounts->getColumn('display_name')->setLength(100);
    }
}
