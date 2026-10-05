<?php

declare(strict_types=1);

namespace HosmelQ\TemporaryUploads\Exceptions;

use RuntimeException;

class UploadExpiredException extends RuntimeException
{
    private function __construct(public readonly string $path)
    {
        parent::__construct(sprintf('Temporary upload [%s] has expired.', $path));
    }

    public static function forUpload(string $path): self
    {
        return new self($path);
    }
}
