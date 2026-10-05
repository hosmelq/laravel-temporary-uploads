<?php

declare(strict_types=1);

namespace HosmelQ\TemporaryUploads\Exceptions;

use RuntimeException;
use Throwable;

class UploadTooLargeException extends RuntimeException
{
    private function __construct(
        public readonly int $maximumSize,
        public readonly string $path,
        public readonly int $size,
        null|Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Temporary upload [%s] is %d bytes, exceeding the %d-byte limit.', $path, $size, $maximumSize),
            previous: $previous,
        );
    }

    public static function forUpload(
        int $maximumSize,
        string $path,
        int $size,
        null|Throwable $previous = null,
    ): self {
        return new self($maximumSize, $path, $size, $previous);
    }
}
