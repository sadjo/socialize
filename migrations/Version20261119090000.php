<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Application Meta propre à un compte (crosspost Instagram).
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261119090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Application Meta par compte';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('instagram_apps');
        $table->addColumn('id', 'uuid');
        $table->addColumn('account_id', 'uuid');
        $table->addColumn('app_id', Types::STRING, ['length' => 64]);
        $table->addColumn('secret_encrypted', Types::TEXT);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['account_id'], 'UNIQ_E0E81A729B6B5FBA');
        $table->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_E0E81A729B6B5FBA');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('instagram_apps');
    }
}
