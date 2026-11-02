<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// Rector sert aux montées de version de PHP et de Symfony :
// les jeux de règles suivent les versions déclarées dans composer.json.
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/public',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/config/bundles.php',
        __DIR__.'/config/reference.php',
    ])
    ->withCache(__DIR__.'/var/cache/rector')
    ->withPhpSets()
    ->withComposerBased(twig: true, doctrine: true, phpunit: true, symfony: true)
    ->withAttributesSets()
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true)
    ->withSymfonyContainerXml(__DIR__.'/var/cache/dev/App_KernelDevDebugContainer.xml');
