<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Jalon 2 : comptes, publications, médias, abonnements, likes et commentaires.
 *
 * Écrite avec l'API Schema (et non en SQL brut) pour produire le bon SQL
 * sur SQLite comme sur MySQL / MariaDB.
 */
final class Version20261112160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comptes, publications, médias, abonnements, likes et commentaires';
    }

    public function up(Schema $schema): void
    {
        $accounts = $schema->createTable('accounts');
        $this->id($accounts);
        $accounts->addColumn('username', Types::STRING, ['length' => 30]);
        $accounts->addColumn('domain', Types::STRING, ['length' => 255, 'notnull' => false]);
        $accounts->addColumn('display_name', Types::STRING, ['length' => 100]);
        $accounts->addColumn('bio', Types::TEXT, ['notnull' => false]);
        $accounts->addColumn('avatar_path', Types::STRING, ['length' => 255, 'notnull' => false]);
        $accounts->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $accounts->addUniqueIndex(['username', 'domain'], 'uniq_accounts_username_domain');

        $users = $schema->createTable('users');
        $this->id($users);
        $users->addColumn('email', Types::STRING, ['length' => 180]);
        $users->addColumn('roles', Types::JSON);
        $users->addColumn('password', Types::STRING, ['length' => 255]);
        $users->addColumn('account_id', 'uuid');
        $users->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $users->addUniqueIndex(['email'], 'uniq_users_email');
        $users->addUniqueIndex(['account_id'], 'UNIQ_1483A5E99B6B5FBA');
        $users->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_1483A5E99B6B5FBA');

        $posts = $schema->createTable('posts');
        $this->id($posts);
        $posts->addColumn('account_id', 'uuid');
        $posts->addColumn('caption', Types::TEXT);
        $posts->addColumn('visibility', Types::STRING, ['length' => 20]);
        $posts->addColumn('content_warning', Types::STRING, ['length' => 500, 'notnull' => false]);
        $posts->addColumn('location', Types::STRING, ['length' => 255, 'notnull' => false]);
        $posts->addColumn('published_at', Types::DATETIME_IMMUTABLE);
        $posts->addColumn('original_created_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $posts->addColumn('source', Types::STRING, ['length' => 30]);
        $posts->addColumn('likes_count', Types::INTEGER);
        $posts->addColumn('comments_count', Types::INTEGER);
        $posts->addIndex(['account_id', 'published_at'], 'idx_posts_account_published');
        $posts->addIndex(['visibility', 'published_at'], 'idx_posts_visibility_published');
        $posts->addIndex(['account_id'], 'IDX_885DBAFA9B6B5FBA');
        $posts->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_885DBAFA9B6B5FBA');

        $media = $schema->createTable('media_attachments');
        $this->id($media);
        $media->addColumn('post_id', 'uuid');
        $media->addColumn('type', Types::STRING, ['length' => 20]);
        $media->addColumn('status', Types::STRING, ['length' => 20]);
        $media->addColumn('position', Types::INTEGER);
        $media->addColumn('original_path', Types::STRING, ['length' => 255]);
        $media->addColumn('mime_type', Types::STRING, ['length' => 100]);
        $media->addColumn('size', Types::BIGINT);
        $media->addColumn('alt_text', Types::TEXT, ['notnull' => false]);
        $media->addColumn('width', Types::INTEGER, ['notnull' => false]);
        $media->addColumn('height', Types::INTEGER, ['notnull' => false]);
        $media->addColumn('duration', Types::FLOAT, ['notnull' => false]);
        $media->addColumn('blurhash', Types::STRING, ['length' => 100, 'notnull' => false]);
        $media->addColumn('derivatives', Types::JSON);
        $media->addColumn('exif', Types::JSON);
        $media->addColumn('failure_reason', Types::STRING, ['length' => 500, 'notnull' => false]);
        $media->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $media->addIndex(['post_id'], 'IDX_B0AC88454B89032C');
        $media->addForeignKeyConstraint('posts', ['post_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_B0AC88454B89032C');

        $follows = $schema->createTable('follows');
        $this->id($follows);
        $follows->addColumn('follower_id', 'uuid');
        $follows->addColumn('following_id', 'uuid');
        $follows->addColumn('state', Types::STRING, ['length' => 20]);
        $follows->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $follows->addUniqueIndex(['follower_id', 'following_id'], 'uniq_follows_pair');
        $follows->addIndex(['follower_id'], 'IDX_4B638A73AC24F853');
        $follows->addIndex(['following_id'], 'IDX_4B638A731816E3A3');
        $follows->addForeignKeyConstraint('accounts', ['follower_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_4B638A73AC24F853');
        $follows->addForeignKeyConstraint('accounts', ['following_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_4B638A731816E3A3');

        $likes = $schema->createTable('likes');
        $this->id($likes);
        $likes->addColumn('account_id', 'uuid');
        $likes->addColumn('post_id', 'uuid');
        $likes->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $likes->addUniqueIndex(['account_id', 'post_id'], 'uniq_likes_account_post');
        $likes->addIndex(['account_id'], 'IDX_49CA4E7D9B6B5FBA');
        $likes->addIndex(['post_id'], 'IDX_49CA4E7D4B89032C');
        $likes->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_49CA4E7D9B6B5FBA');
        $likes->addForeignKeyConstraint('posts', ['post_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_49CA4E7D4B89032C');

        $comments = $schema->createTable('comments');
        $this->id($comments);
        $comments->addColumn('post_id', 'uuid');
        $comments->addColumn('account_id', 'uuid');
        $comments->addColumn('content', Types::TEXT);
        $comments->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $comments->addIndex(['post_id', 'created_at'], 'idx_comments_post_created');
        $comments->addIndex(['post_id'], 'IDX_5F9E962A4B89032C');
        $comments->addIndex(['account_id'], 'IDX_5F9E962A9B6B5FBA');
        $comments->addForeignKeyConstraint('posts', ['post_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_5F9E962A4B89032C');
        $comments->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_5F9E962A9B6B5FBA');
    }

    public function down(Schema $schema): void
    {
        foreach (['comments', 'likes', 'follows', 'media_attachments', 'posts', 'users', 'accounts'] as $table) {
            $schema->dropTable($table);
        }
    }

    private function id(Table $table): void
    {
        $table->addColumn('id', 'uuid');
        $table->setPrimaryKey(['id']);
    }
}
