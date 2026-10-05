<?php

declare(strict_types=1);

namespace HosmelQ\TemporaryUploads;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;

final readonly class TemporaryFile
{
    public function __construct(
        public string $disk,
        public string $filename,
        private FilesystemAdapter $filesystem,
        public CarbonImmutable $lastModified,
        public null|string $originalName,
        public string $path,
        public int $size,
    ) {
    }

    public function contents(): string
    {
        return $this->filesystem->getDriver()->read($this->path);
    }

    /**
     * @return resource
     */
    public function readStream(): mixed
    {
        return $this->filesystem->getDriver()->readStream($this->path);
    }
}
