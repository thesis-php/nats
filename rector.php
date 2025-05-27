<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Php80\Rector\Class_\StringableForToStringRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/examples',
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withParallel()
    ->withCache(__DIR__ . '/var/rector')
    ->withPhpSets()
    ->withSkip([
        StringableForToStringRector::class,
        AddOverrideAttributeToOverriddenMethodsRector::class,
        ClosureToArrowFunctionRector::class => [
            __DIR__.'/src/Header/Value.php',
        ],
    ]);
