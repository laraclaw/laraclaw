<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withImportNames(importDocBlockNames: true, importShortClasses: false)
    ->withSets([
        LevelSetList::UP_TO_PHP_84,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        LaravelSetList::LARAVEL_120,
    ])
    ->withSkip([
        // Conflicts with the project style guide — prefer `! $foo` over `!$foo instanceof X`
        Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector::class,
        // Breaks intentional logical ordering of named arguments
        Rector\CodeQuality\Rector\FuncCall\SortCallLikeNamedArgsRector::class,
    ]);
