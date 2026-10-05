<?php

declare(strict_types=1);

namespace HosmelQ\TemporaryUploads;

use HosmelQ\TemporaryUploads\Commands\PruneTemporaryUploads;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class TemporaryUploadsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-temporary-uploads')
            ->hasCommand(PruneTemporaryUploads::class)
            ->hasConfigFile();
    }
}
