<?php

declare(strict_types=1);

namespace App\Tests\Core;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Les migrations ne doivent jamais perdre de données sur une base SQLite remplie.
 *
 * Régression du 12 novembre 2026 : avec les clés étrangères actives pendant la migration,
 * la reconstruction de la table « accounts » a vidé « users », « posts » et « media_attachments »
 * par ON DELETE CASCADE. Le conteneur migre donc avec SQLITE_FOREIGN_KEYS=0.
 */
final class MigrationSafetyTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        $this->database = sys_get_temp_dir().'/socialize-migration-'.bin2hex(random_bytes(4)).'.db';
    }

    protected function tearDown(): void
    {
        @unlink($this->database);
    }

    public function testMigratingAFilledDatabaseKeepsAllRows(): void
    {
        $this->console(['doctrine:migrations:migrate', 'DoctrineMigrations\Version20261112180000', '-n']);
        foreach ([
            "INSERT INTO accounts (id, username, domain, display_name, created_at) VALUES (X'01A1D0DE0CA1703B93AF9720DCE9FFB7', 'alice', NULL, 'Alice', '2026-11-12 17:46:15')",
            "INSERT INTO users (id, email, roles, password, account_id, created_at) VALUES (X'01A1D0DE0CA1703B93AF9720DCE9FFB8', 'alice@exemple.test', '[]', 'x', X'01A1D0DE0CA1703B93AF9720DCE9FFB7', '2026-11-12 17:46:15')",
            "INSERT INTO posts (id, account_id, caption, visibility, published_at, source, likes_count, comments_count) VALUES (X'01A1D0DE0CA1703B93AF9720DCE9FFB9', X'01A1D0DE0CA1703B93AF9720DCE9FFB7', 'Test', 'public', '2026-11-12 18:00:00', 'local', 0, 0)",
            "INSERT INTO media_attachments (id, post_id, type, status, position, original_path, mime_type, size, derivatives, exif, created_at) VALUES (X'01A1D0DE0CA1703B93AF9720DCE9FFBA', X'01A1D0DE0CA1703B93AF9720DCE9FFB9', 'image', 'ready', 0, 'a.jpg', 'image/jpeg', 1, '{}', '{}', '2026-11-12 18:00:00')",
        ] as $sql) {
            $this->console(['dbal:run-sql', $sql]);
        }

        // Comme l'entrypoint du conteneur : migrations sans clés étrangères.
        $this->console(['doctrine:migrations:migrate', '-n']);

        $pdo = new \PDO('sqlite:'.$this->database, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        foreach (['users', 'accounts', 'posts', 'media_attachments'] as $table) {
            self::assertSame(1, (int) $this->query($pdo, 'SELECT COUNT(*) FROM '.$table)->fetchColumn(), \sprintf('La table %s a perdu des lignes.', $table));
        }
        self::assertSame([], $this->query($pdo, 'PRAGMA foreign_key_check')->fetchAll(), 'Aucune référence cassée après migration.');
    }

    private function query(\PDO $pdo, string $sql): \PDOStatement
    {
        $statement = $pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return $statement;
    }

    /**
     * @param list<string> $arguments
     */
    private function console(array $arguments): void
    {
        $process = new Process(['php', \dirname(__DIR__, 2).'/bin/console', ...$arguments, '--env=test'], env: [
            'DATABASE_URL' => 'sqlite:///'.$this->database,
            'SQLITE_FOREIGN_KEYS' => '0',
        ]);
        $process->setTimeout(120);
        $process->mustRun();
    }
}
