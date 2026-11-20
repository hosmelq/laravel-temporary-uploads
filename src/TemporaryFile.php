<?php

declare(strict_types=1);

namespace HosmelQ\TemporaryUploads;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\AwsS3V3Adapter;
use League\Flysystem\UnableToReadFile;
use Psr\Http\Message\StreamInterface;
use Throwable;
use UnexpectedValueException;

final readonly class TemporaryFile
{
    public function __construct(
        public string $disk,
        private string $etag,
        public string $filename,
        private AwsS3V3Adapter $filesystem,
        public CarbonImmutable $lastModified,
        public null|string $originalName,
        public string $path,
        public int $size,
    ) {}

    public function contents(): string
    {
        return $this->read(false)->getContents();
    }

    /**
     * @return resource
     */
    public function readStream(): mixed
    {
        $resource = $this->read(true)->detach();

        if (! is_resource($resource)) {
            throw UnableToReadFile::fromLocation(
                $this->path,
                'Storage did not return a readable stream.',
            );
        }

        return $resource;
    }

    /**
     * Read the object only while it is still the version that was retrieved.
     */
    private function read(bool $wantsStream): StreamInterface
    {
        $client = $this->filesystem->getClient();
        $config = $this->filesystem->getConfig();
        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
        $http = is_array($options['@http'] ?? null) ? $options['@http'] : [];
        $arguments = [
            'Bucket' => $config['bucket'],
            'IfMatch' => $this->etag,
            'Key' => $this->filesystem->path($this->path),
        ];

        if ($wantsStream && (bool) ($config['stream_reads'] ?? false) && ! isset($http['stream'])) {
            $arguments['@http'] = $http + ['stream' => true];
        }

        try {
            $body = $client->execute($client->getCommand('GetObject', $arguments + $options))
                ->get('Body');
        } catch (Throwable $throwable) {
            throw UnableToReadFile::fromLocation($this->path, $throwable->getMessage(), $throwable);
        }

        if (! $body instanceof StreamInterface) {
            throw new UnexpectedValueException(
                'Storage returned an invalid temporary upload body.',
            );
        }

        return $body;
    }
}
