<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    ->ignoreErrors([ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnPackages([
        'illuminate/config',
        'illuminate/console',
        'illuminate/contracts',
        'illuminate/filesystem',
        'illuminate/http',
        'illuminate/support',
        'league/flysystem-aws-s3-v3',
    ], [ErrorType::UNUSED_DEPENDENCY]);
