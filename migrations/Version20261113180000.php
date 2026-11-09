<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Jalon 5 : import d'archives Instagram (imports et éléments), annonce des publications au fédivers.
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261113180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Import Instagram';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('posts')->addColumn('announce', Types::BOOLEAN, ['default' => true]);

        $imports = $schema->createTable('instagram_imports');
        $imports->addColumn('id', 'uuid');
        $imports->setPrimaryKey(['id']);
        $imports->addColumn('account_id', 'uuid');
        $imports->addColumn('status', Types::STRING, ['length' => 20]);
        $imports->addColumn('parts', Types::JSON);
        $imports->addColumn('placement', Types::STRING, ['length' => 20]);
        $imports->addColumn('keep_original_dates', Types::BOOLEAN);
        $imports->addColumn('import_stories', Types::BOOLEAN);
        $imports->addColumn('counts', Types::JSON);
        $imports->addColumn('warnings', Types::JSON);
        $imports->addColumn('log', Types::JSON);
        $imports->addColumn('error', Types::STRING, ['length' => 1000, 'notnull' => false]);
        $imports->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $imports->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $imports->addIndex(['account_id', 'created_at'], 'idx_instagram_imports_account');
        $imports->addIndex(['account_id'], 'IDX_F58A62129B6B5FBA');
        $imports->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_F58A62129B6B5FBA');

        $items = $schema->createTable('instagram_import_items');
        $items->addColumn('id', 'uuid');
        $items->setPrimaryKey(['id']);
        $items->addColumn('import_id', 'uuid');
        $items->addColumn('account_id', 'uuid');
        $items->addColumn('kind', Types::STRING, ['length' => 20]);
        $items->addColumn('fingerprint', Types::STRING, ['length' => 64]);
        $items->addColumn('taken_at', Types::DATETIME_IMMUTABLE);
        $items->addColumn('caption', Types::TEXT);
        $items->addColumn('media_uris', Types::JSON);
        $items->addColumn('exif', Types::JSON);
        $items->addColumn('raw', Types::JSON);
        $items->addColumn('status', Types::STRING, ['length' => 20]);
        $items->addColumn('choice', Types::STRING, ['length' => 20, 'notnull' => false]);
        $items->addColumn('message', Types::STRING, ['length' => 500, 'notnull' => false]);
        $items->addColumn('target_id', 'uuid', ['notnull' => false]);
        $items->addUniqueIndex(['import_id', 'fingerprint'], 'uniq_instagram_items_import_fingerprint');
        $items->addIndex(['account_id', 'fingerprint'], 'idx_instagram_items_account_fingerprint');
        $items->addIndex(['import_id', 'status', 'taken_at'], 'idx_instagram_items_import_status');
        $items->addIndex(['import_id'], 'IDX_9F165CC4B6A263D9');
        $items->addIndex(['account_id'], 'IDX_9F165CC49B6B5FBA');
        $items->addForeignKeyConstraint('instagram_imports', ['import_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_9F165CC4B6A263D9');
        $items->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_9F165CC49B6B5FBA');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('instagram_import_items');
        $schema->dropTable('instagram_imports');
        $schema->getTable('posts')->dropColumn('announce');
    }
}
