<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/examples'])
    // Upgrade rules up to PHP 8.1, the minimum version in composer.json, so nothing newer sneaks in.
    ->withPhpSets(php81: true)
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true)
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withSkip([
        // Inline "/** @var … */" hints on decoded JSON are what PHPStan uses to type it.
        RemoveNonExistingVarAnnotationRector::class,
        // Static test helpers called with self:: are the usual PHPUnit style.
        LocallyCalledStaticMethodToNonStaticRector::class,
    ]);
