<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Jalon 3 : stories, stories à la une et vues. Un média appartient à une publication ou à une story.
 * Écrite avec l'API Schema pour rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261112180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stories, stories à la une et vues';
    }

    public function up(Schema $schema): void
    {
        $highlights = $schema->createTable('story_highlights');
        $highlights->addColumn('id', 'uuid');
        $highlights->setPrimaryKey(['id']);
        $highlights->addColumn('account_id', 'uuid');
        $highlights->addColumn('title', Types::STRING, ['length' => 30]);
        $highlights->addColumn('position', Types::INTEGER);
        $highlights->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $highlights->addIndex(['account_id', 'position'], 'idx_highlights_account_position');
        $highlights->addIndex(['account_id'], 'IDX_1D8858F69B6B5FBA');
        $highlights->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_1D8858F69B6B5FBA');

        $stories = $schema->createTable('stories');
        $stories->addColumn('id', 'uuid');
        $stories->setPrimaryKey(['id']);
        $stories->addColumn('account_id', 'uuid');
        $stories->addColumn('highlight_id', 'uuid', ['notnull' => false]);
        $stories->addColumn('text', Types::STRING, ['length' => 200, 'notnull' => false]);
        $stories->addColumn('audience', Types::STRING, ['length' => 20]);
        $stories->addColumn('views_count', Types::INTEGER);
        $stories->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $stories->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $stories->addIndex(['account_id', 'expires_at'], 'idx_stories_account_expires');
        $stories->addIndex(['highlight_id'], 'IDX_9C8B9D5FF216DCD4');
        $stories->addIndex(['account_id'], 'IDX_9C8B9D5F9B6B5FBA');
        $stories->addForeignKeyConstraint('story_highlights', ['highlight_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_9C8B9D5FF216DCD4');
        $stories->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_9C8B9D5F9B6B5FBA');

        $views = $schema->createTable('story_views');
        $views->addColumn('id', 'uuid');
        $views->setPrimaryKey(['id']);
        $views->addColumn('story_id', 'uuid');
        $views->addColumn('account_id', 'uuid');
        $views->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $views->addUniqueIndex(['story_id', 'account_id'], 'uniq_story_views_pair');
        $views->addIndex(['story_id'], 'IDX_CDB03C24AA5D4036');
        $views->addIndex(['account_id'], 'IDX_CDB03C249B6B5FBA');
        $views->addForeignKeyConstraint('stories', ['story_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_CDB03C24AA5D4036');
        $views->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_CDB03C249B6B5FBA');

        $media = $schema->getTable('media_attachments');
        $media->getColumn('post_id')->setNotnull(false);
        $media->addColumn('story_id', 'uuid', ['notnull' => false]);
        $media->addUniqueIndex(['story_id'], 'UNIQ_B0AC8845AA5D4036');
        $media->addForeignKeyConstraint('stories', ['story_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_B0AC8845AA5D4036');
    }

    public function down(Schema $schema): void
    {
        $media = $schema->getTable('media_attachments');
        $media->removeForeignKey('FK_B0AC8845AA5D4036');
        $media->dropIndex('UNIQ_B0AC8845AA5D4036');
        $media->dropColumn('story_id');
        $media->getColumn('post_id')->setNotnull(true);

        $schema->dropTable('story_views');
        $schema->dropTable('stories');
        $schema->dropTable('story_highlights');
    }
}
