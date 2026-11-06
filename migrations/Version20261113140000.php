<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Listes des abonnés et abonnements réservées aux abonnés.
 */
final class Version20261113140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Option de masquage des abonnés et abonnements';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('accounts')->addColumn('network_hidden', Types::BOOLEAN, ['default' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('accounts')->dropColumn('network_hidden');
    }
}
