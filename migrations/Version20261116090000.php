<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Droit de publier (ROLE_PUBLISHER), désormais accordé par l'administration : les comptes existants le gardent.
 * Données seulement (colonne JSON roles), en SQL portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261116090000 extends AbstractMigration
{
    private const string ROLE = 'ROLE_PUBLISHER';

    public function getDescription(): string
    {
        return 'Droit de publier accordé aux comptes existants';
    }

    public function up(Schema $schema): void
    {
        $this->rewriteRoles(static fn (array $roles): array => array_values(array_unique([...$roles, self::ROLE])));
    }

    public function down(Schema $schema): void
    {
        $this->rewriteRoles(static fn (array $roles): array => array_values(array_filter($roles, static fn (mixed $role): bool => self::ROLE !== $role)));
    }

    /**
     * @param \Closure(list<mixed>): list<mixed> $change
     */
    private function rewriteRoles(\Closure $change): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, roles FROM users') as $row) {
            $roles = json_decode(\is_string($row['roles']) ? $row['roles'] : '[]', true);
            $roles = \is_array($roles) ? array_values($roles) : [];
            $this->addSql('UPDATE users SET roles = ? WHERE id = ?', [json_encode($change($roles), \JSON_THROW_ON_ERROR), $row['id']]);
        }
    }
}
