<?php

declare(strict_types=1);

namespace HosmelQ\TemporaryUploads\Support;

use Illuminate\Support\Facades\Config as ConfigFacade;
use InvalidArgumentException;

class Config
{
    public static function deleteRejectedUploads(): bool
    {
        return ConfigFacade::boolean('temporary-uploads.delete_rejected_uploads');
    }

    public static function disk(): string
    {
        return ConfigFacade::string('temporary-uploads.disk');
    }

    public static function maxSize(): int
    {
        $value = ConfigFacade::integer('temporary-uploads.max_size');

        if ($value < 0) {
            throw new InvalidArgumentException('The maximum upload size cannot be negative.');
        }

        return $value;
    }

    public static function prefix(): string
    {
        return ConfigFacade::string('temporary-uploads.prefix');
    }

    public static function retention(): int
    {
        $value = ConfigFacade::integer('temporary-uploads.retention');

        if ($value < 1) {
            throw new InvalidArgumentException('The temporary upload retention must be greater than zero.');
        }

        return $value;
    }

    public static function urlExpiration(): int
    {
        $value = ConfigFacade::integer('temporary-uploads.url_expiration');

        if ($value < 1) {
            throw new InvalidArgumentException('The temporary upload url_expiration must be greater than zero.');
        }

        if ($value > 604800) {
            throw new InvalidArgumentException('The upload URL lifetime cannot exceed seven days.');
        }

        return $value;
    }
}
