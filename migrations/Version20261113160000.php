<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Archivage des publications (visibles par leur seul auteur, hors des fils et du fédivers).
 */
final class Version20261113160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Archivage des publications';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('posts')->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('posts')->dropColumn('archived_at');
    }
}
