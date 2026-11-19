<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aperçu du premier lien des publications texte : adresse, état, site, titre, description et copie locale de l'image.
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261205090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aperçus de liens des publications texte';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('posts');
        $table->addColumn('preview_url', Types::STRING, ['length' => 1000, 'notnull' => false]);
        $table->addColumn('preview_state', Types::STRING, ['length' => 10, 'notnull' => false]);
        $table->addColumn('preview_site', Types::STRING, ['length' => 200, 'notnull' => false]);
        $table->addColumn('preview_title', Types::STRING, ['length' => 300, 'notnull' => false]);
        $table->addColumn('preview_description', Types::STRING, ['length' => 500, 'notnull' => false]);
        $table->addColumn('preview_image', Types::STRING, ['length' => 255, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('posts');
        foreach (['preview_url', 'preview_state', 'preview_site', 'preview_title', 'preview_description', 'preview_image'] as $column) {
            $table->dropColumn($column);
        }
    }
}
