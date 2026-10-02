<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/examples']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP81Migration' => true,
        'declare_strict_types' => true,
        'concat_space' => ['spacing' => 'one'],
        'global_namespace_import' => ['import_classes' => false, 'import_constants' => false, 'import_functions' => false],
        // Keep inline "/** @var … */" type hints: PHPStan reads them.
        'phpdoc_to_comment' => ['ignored_tags' => ['var']],
        // Don't prefix every native function call with a backslash.
        'native_function_invocation' => false,
    ])
    ->setFinder($finder);
