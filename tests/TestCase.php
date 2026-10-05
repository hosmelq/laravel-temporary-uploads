<?php

declare(strict_types=1);

namespace HosmelQ\TemporaryUploads\Tests;

use Aws\MockHandler;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    use WithWorkbench;

    protected function mockStorage(): MockHandler
    {
        $handler = new MockHandler();

        Config::set('filesystems.disks.uploads', [
            'bucket' => 'test-bucket',
            'driver' => 's3',
            'endpoint' => 'https://storage.example.test',
            'handler' => $handler,
            'key' => 'test-key',
            'region' => 'us-east-1',
            'retries' => 0,
            'root' => 'application',
            'secret' => 'test-secret',
            'use_path_style_endpoint' => true,
        ]);
        Config::set('temporary-uploads.disk', 'uploads');
        Storage::forgetDisk('uploads');

        return $handler;
    }
}
