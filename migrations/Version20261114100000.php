<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Groupes d'abonnés (Famille, Amis…) : publications et stories réservées à des groupes, groupe à l'import Instagram.
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261114100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Groupes d’abonnés';
    }

    public function up(Schema $schema): void
    {
        $groups = $schema->createTable('audience_groups');
        $groups->addColumn('id', 'uuid');
        $groups->setPrimaryKey(['id']);
        $groups->addColumn('owner_id', 'uuid');
        $groups->addColumn('name', Types::STRING, ['length' => 50]);
        $groups->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $groups->addIndex(['owner_id'], 'idx_audience_groups_owner');
        $groups->addForeignKeyConstraint('accounts', ['owner_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_BE11BA1F7E3C61F9');

        $members = $schema->createTable('audience_group_members');
        $members->addColumn('group_id', 'uuid');
        $members->addColumn('account_id', 'uuid');
        $members->setPrimaryKey(['group_id', 'account_id']);
        $members->addIndex(['group_id'], 'IDX_6193FE49FE54D947');
        $members->addIndex(['account_id'], 'IDX_6193FE499B6B5FBA');
        $members->addForeignKeyConstraint('audience_groups', ['group_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_6193FE49FE54D947');
        $members->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_6193FE499B6B5FBA');

        foreach ([['post_audience_groups', 'post_id', 'posts', 'F3277A774B89032C', 'F3277A77FE54D947'], ['story_audience_groups', 'story_id', 'stories', '5D2422FDAA5D4036', '5D2422FDFE54D947']] as [$name, $column, $target, $ownerKey, $groupKey]) {
            $table = $schema->createTable($name);
            $table->addColumn($column, 'uuid');
            $table->addColumn('group_id', 'uuid');
            $table->setPrimaryKey([$column, 'group_id']);
            $table->addIndex([$column], 'IDX_'.$ownerKey);
            $table->addIndex(['group_id'], 'IDX_'.$groupKey);
            $table->addForeignKeyConstraint($target, [$column], ['id'], ['onDelete' => 'CASCADE'], 'FK_'.$ownerKey);
            $table->addForeignKeyConstraint('audience_groups', ['group_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_'.$groupKey);
        }

        $schema->getTable('instagram_imports')->addColumn('default_group', Types::STRING, ['length' => 40, 'notnull' => false]);
        $schema->getTable('instagram_import_items')->addColumn('group_choice', Types::STRING, ['length' => 40, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('instagram_import_items')->dropColumn('group_choice');
        $schema->getTable('instagram_imports')->dropColumn('default_group');
        $schema->dropTable('story_audience_groups');
        $schema->dropTable('post_audience_groups');
        $schema->dropTable('audience_group_members');
        $schema->dropTable('audience_groups');
    }
}
