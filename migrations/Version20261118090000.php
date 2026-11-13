<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Publication en attente pendant la conversion de ses vidéos, et progression de cette conversion.
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261118090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Publications en attente et progression des conversions vidéo';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('posts')->addColumn('pending', Types::BOOLEAN, ['default' => false]);
        $schema->getTable('media_attachments')->addColumn('progress', Types::SMALLINT, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('posts')->dropColumn('pending');
        $schema->getTable('media_attachments')->dropColumn('progress');
    }
}
