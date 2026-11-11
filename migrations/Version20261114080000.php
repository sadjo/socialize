<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Invitations à créer un compte (lien ou e-mail), lancées par l'administrateur.
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261114080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invitations';
    }

    public function up(Schema $schema): void
    {
        $invitations = $schema->createTable('invitations');
        $invitations->addColumn('id', 'uuid');
        $invitations->setPrimaryKey(['id']);
        $invitations->addColumn('token_hash', Types::STRING, ['length' => 64]);
        $invitations->addColumn('email', Types::STRING, ['length' => 180, 'notnull' => false]);
        $invitations->addColumn('invited_by_id', 'uuid', ['notnull' => false]);
        $invitations->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $invitations->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $invitations->addColumn('used_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $invitations->addColumn('used_by_id', 'uuid', ['notnull' => false]);
        $invitations->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $invitations->addUniqueIndex(['token_hash'], 'UNIQ_232710AEB3BC57DA');
        $invitations->addIndex(['created_at'], 'idx_invitations_created');
        $invitations->addIndex(['used_by_id'], 'IDX_232710AE4C2B72A8');
        $invitations->addIndex(['invited_by_id'], 'IDX_232710AEA7B4A7E3');
        $invitations->addForeignKeyConstraint('accounts', ['used_by_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_232710AE4C2B72A8');
        $invitations->addForeignKeyConstraint('accounts', ['invited_by_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_232710AEA7B4A7E3');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('invitations');
    }
}
