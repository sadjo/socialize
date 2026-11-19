<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Apparence (thème, filtre des médias) choisie par les personnes connectées avec Mastodon, comme pour les comptes de
 * l'instance. Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261203090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Apparence des comptes Mastodon connectés';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('mastodon_users');
        $table->addColumn('theme', Types::STRING, ['length' => 16, 'default' => 'auto']);
        $table->addColumn('media_filter', Types::STRING, ['length' => 16, 'default' => 'light']);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('mastodon_users');
        $table->dropColumn('theme');
        $table->dropColumn('media_filter');
    }
}
