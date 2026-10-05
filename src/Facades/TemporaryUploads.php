<?php

declare(strict_types=1);

namespace HosmelQ\TemporaryUploads\Facades;

use HosmelQ\TemporaryUploads\TemporaryFile;
use HosmelQ\TemporaryUploads\TemporaryUploads as UploadManager;
use HosmelQ\TemporaryUploads\UploadUrl;
use Illuminate\Support\Facades\Facade;

/**
 * @method static UploadUrl create(string $filename)
 * @method static UploadManager maxSize(int $bytes)
 * @method static int prune()
 * @method static TemporaryFile retrieve(string $path)
 *
 * @see UploadManager
 */
class TemporaryUploads extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return UploadManager::class;
    }
}
