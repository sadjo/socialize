<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Visibilité par défaut des publications d'un compte Mastodon connecté (« abonnés » pour un compte privé), proposée
 * à la publication depuis Socialize. Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261126090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Visibilité par défaut d’un compte Mastodon connecté';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('mastodon_users')->addColumn('default_visibility', Types::STRING, ['length' => 20, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('mastodon_users')->dropColumn('default_visibility');
    }
}
