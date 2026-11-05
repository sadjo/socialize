<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Version de l'identifiant ActivityPub des publications (retour dans le fédivers après un passage en privé).
 */
final class Version20261113100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Version de l’identifiant ActivityPub des publications';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('posts')->addColumn('ap_revision', Types::INTEGER, ['default' => 1]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('posts')->dropColumn('ap_revision');
    }
}
