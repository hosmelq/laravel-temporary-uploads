<?php

declare(strict_types=1);

use Pest\Rector\Set\PestSetList;
use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Config\RectorConfig;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;

return RectorConfig::configure()
    ->withCache('./.cache/rector', FileCacheStorage::class)
    ->withImportNames()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/workbench',
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        carbon: true,
        codeQuality: true,
        codingStyle: true,
        deadCode: true,
        earlyReturn: true,
        instanceOf: true,
        rectorPreset: true,
        typeDeclarations: true
    )
    ->withSets([
        PestSetList::CODING_STYLE,
    ])
    ->withSkip([
        ClosureToArrowFunctionRector::class,
    ]);
