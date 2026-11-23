<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Dossier des médias et fichiers écrits pendant les tests, propre à chaque processus de ParaTest (TEST_TOKEN),
 * comme dans config/packages/flysystem.yaml et config/services.yaml.
 */
final class TestStorage
{
    public static function dir(string $path = ''): string
    {
        $dir = \dirname(__DIR__, 2).'/var/test-storage'.(getenv('TEST_TOKEN') ?: '');

        return '' === $path ? $dir : $dir.'/'.ltrim($path, '/');
    }
}
