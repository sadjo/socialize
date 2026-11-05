<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Modification des publications : date de dernière modification.
 */
final class Version20261113090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Date de modification des publications';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('posts')->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('posts')->dropColumn('updated_at');
    }
}
