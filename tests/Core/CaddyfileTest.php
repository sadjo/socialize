<?php

declare(strict_types=1);

namespace App\Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * Serveur web de l'image : les assets compilés (nom avec empreinte) sont gardés un an par le navigateur, sans
 * revalidation au lancement de l'application ; une adresse d'asset inexistante n'est pas concernée.
 */
final class CaddyfileTest extends TestCase
{
    public function testCompiledAssetsAreCachedForAYear(): void
    {
        $caddyfile = (string) file_get_contents(__DIR__.'/../../docker/frankenphp/Caddyfile');

        self::assertMatchesRegularExpression('/@assets \{\s*path \/assets\/\*\s*file\s*\}/', $caddyfile);
        self::assertStringContainsString('header @assets Cache-Control "public, max-age=31536000, immutable"', $caddyfile);
    }
}
