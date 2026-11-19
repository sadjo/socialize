<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Comptes distants : date de lecture de leur historique, et nombres d'abonnés, d'abonnements et de publications donnés
 * par leur instance. Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261204090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Historique et compteurs des comptes distants';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('accounts');
        $table->addColumn('backfilled_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('remote_followers_count', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('remote_following_count', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('remote_posts_count', Types::INTEGER, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('accounts');
        foreach (['backfilled_at', 'remote_followers_count', 'remote_following_count', 'remote_posts_count'] as $column) {
            $table->dropColumn($column);
        }
    }
}
