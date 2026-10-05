<?php

declare(strict_types=1);

namespace HosmelQ\TemporaryUploads;

use function Safe\base64_decode;
use function Safe\preg_match;

use Aws\S3\Exception\S3Exception;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use HosmelQ\TemporaryUploads\Exceptions\UploadExpiredException;
use HosmelQ\TemporaryUploads\Exceptions\UploadTooLargeException;
use HosmelQ\TemporaryUploads\Support\Config;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\WhitespacePathNormalizer;
use Safe\Exceptions\UrlException;
use UnexpectedValueException;

class TemporaryUploads
{
    private const string ORIGINAL_NAME_METADATA = 'original-name';

    private null|int $maximumSize = null;

    public function __construct(
        private readonly FilesystemManager $filesystems,
    ) {
    }

    public function create(string $filename): UploadUrl
    {
        $normalizedFilename = $this->normalizeFilename($filename);
        $originalName = base64_encode($filename);

        if (mb_strlen($originalName, '8bit') + mb_strlen(self::ORIGINAL_NAME_METADATA, '8bit') > 2048) {
            throw new InvalidArgumentException('The original filename exceeds the metadata size limit.');
        }

        $path = $this->prefix().'/'.Str::ulid().'/'.$normalizedFilename;
        $filesystem = $this->filesystem();

        if (mb_strlen($filesystem->path($path), '8bit') > 1024) {
            throw new InvalidArgumentException('The temporary upload path exceeds 1024 bytes.');
        }

        $expiresAt = CarbonImmutable::now()->addSeconds(Config::urlExpiration());
        $upload = $filesystem->temporaryUploadUrl($path, $expiresAt, [
            'Metadata' => [self::ORIGINAL_NAME_METADATA => $originalName],
        ]);

        $headers = [];
        $uploadHeaders = $upload['headers'];
        $url = $upload['url'];

        if (! is_array($uploadHeaders) || ! is_string($url)) {
            throw new UnexpectedValueException('Storage returned invalid upload instructions.');
        }

        foreach ($uploadHeaders as $name => $values) {
            if (! is_string($name) || ! is_array($values) || ! array_is_list($values)) {
                throw new UnexpectedValueException('Storage returned invalid upload headers.');
            }

            $headers[$name] = [];

            foreach ($values as $value) {
                if (! is_string($value)) {
                    throw new UnexpectedValueException('Storage returned invalid upload headers.');
                }

                $headers[$name][] = $value;
            }
        }

        return new UploadUrl(
            expiresAt: $expiresAt,
            headers: $headers,
            path: $path,
            url: $url,
        );
    }

    public function maxSize(int $bytes): self
    {
        if ($bytes < 0) {
            throw new InvalidArgumentException('The maximum upload size cannot be negative.');
        }

        $uploads = clone $this;
        $uploads->maximumSize = $bytes;

        return $uploads;
    }

    public function prune(): int
    {
        $prefix = $this->prefix();
        $cutoff = CarbonImmutable::now()->getTimestamp() - Config::retention();
        $filesystem = $this->filesystem();
        $count = 0;

        foreach ($filesystem->getDriver()->listContents($prefix, true) as $file) {
            if (! $file instanceof FileAttributes || ! $this->isUploadPath($file->path(), $prefix)) {
                continue;
            }

            $lastModified = $file->lastModified();

            if ($lastModified === null) {
                throw new UnexpectedValueException('Storage did not return the upload modification time.');
            }

            if ($lastModified > $cutoff) {
                continue;
            }

            $filesystem->getDriver()->delete($file->path());
            ++$count;
        }

        return $count;
    }

    public function retrieve(string $path): TemporaryFile
    {
        if (! $this->isUploadPath($path, $this->prefix())) {
            throw new InvalidArgumentException('The path is not a temporary upload in the configured prefix.');
        }

        $maximumSize = $this->maximumSize ?? Config::maxSize();
        $retention = Config::retention();
        $filesystem = $this->filesystem();
        $bucket = $filesystem->getConfig()['bucket'];

        if (! is_string($bucket) || $bucket === '') {
            throw new InvalidArgumentException('The S3 disk must define a bucket.');
        }

        try {
            $metadata = $filesystem->getClient()->execute(
                $filesystem->getClient()->getCommand('HeadObject', [
                    'Bucket' => $bucket,
                    'Key' => $filesystem->path($path),
                ]),
            );
        } catch (S3Exception $s3Exception) {
            if ($s3Exception->getStatusCode() !== 404) {
                throw $s3Exception;
            }

            throw new FileNotFoundException("Temporary upload [{$path}] was not found.", $s3Exception->getCode(), previous: $s3Exception);
        }

        $lastModified = $metadata->get('LastModified');
        $size = $metadata->get('ContentLength');

        if (! $lastModified instanceof DateTimeInterface || ! is_int($size) || $size < 0) {
            throw new UnexpectedValueException('Storage returned invalid temporary upload metadata.');
        }

        if ($lastModified->getTimestamp() <= CarbonImmutable::now()->getTimestamp() - $retention) {
            throw UploadExpiredException::forUpload($path);
        }

        if ($size > $maximumSize) {
            $this->rejectOversizedUpload($filesystem, $maximumSize, $path, $size);
        }

        $customMetadata = $metadata->get('Metadata');
        $originalName = is_array($customMetadata)
            ? ($customMetadata[self::ORIGINAL_NAME_METADATA] ?? null)
            : null;

        return new TemporaryFile(
            disk: Config::disk(),
            filename: basename($path),
            filesystem: $filesystem,
            lastModified: CarbonImmutable::instance($lastModified),
            originalName: is_string($originalName) ? $this->decodeOriginalName($originalName) : null,
            path: $path,
            size: $size,
        );
    }

    private function decodeOriginalName(string $value): null|string
    {
        try {
            $name = base64_decode($value, true);
        } catch (UrlException) {
            return null;
        }

        return $name !== '' && mb_check_encoding($name, 'UTF-8') ? $name : null;
    }

    private function filesystem(): AwsS3V3Adapter
    {
        $filesystem = $this->filesystems->disk(Config::disk());

        if (! $filesystem instanceof AwsS3V3Adapter) {
            throw new InvalidArgumentException('Temporary uploads require an S3-compatible disk.');
        }

        return $filesystem;
    }

    private function isUploadPath(string $path, string $prefix): bool
    {
        try {
            $normalized = new WhitespacePathNormalizer()->normalizePath($path);
        } catch (FilesystemException) {
            return false;
        }

        if ($normalized !== $path || ! str_starts_with($path, $prefix.'/')) {
            return false;
        }

        $parts = explode('/', mb_substr($path, mb_strlen($prefix) + 1));

        return count($parts) === 2 && Str::isUlid($parts[0]) && $parts[1] !== '';
    }

    private function normalizeFilename(string $filename): string
    {
        if (! mb_check_encoding($filename, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $filename) === 1) {
            throw new InvalidArgumentException('The filename must be valid UTF-8 without control characters.');
        }

        $name = mb_trim(basename(str_replace('\\', '/', $filename)));

        if (in_array($name, ['', '.', '..'], true)) {
            throw new InvalidArgumentException('The filename must contain a name.');
        }

        $extension = Str::slug(pathinfo($name, PATHINFO_EXTENSION));
        $stem = Str::slug(pathinfo($name, PATHINFO_FILENAME));
        $stem = $stem === '' ? 'file' : $stem;

        return $extension === '' ? $stem : $stem.'.'.$extension;
    }

    private function prefix(): string
    {
        $prefix = mb_rtrim(Config::prefix(), '/');

        try {
            $normalized = new WhitespacePathNormalizer()->normalizePath($prefix);
        } catch (FilesystemException $filesystemException) {
            throw new InvalidArgumentException('The temporary upload prefix is invalid.', $filesystemException->getCode(), previous: $filesystemException);
        }

        if ($prefix === '' || $normalized !== $prefix) {
            throw new InvalidArgumentException('A non-empty relative temporary upload prefix is required.');
        }

        return $prefix;
    }

    private function rejectOversizedUpload(
        AwsS3V3Adapter $filesystem,
        int $maximumSize,
        string $path,
        int $size,
    ): never {
        $previous = null;

        if (Config::deleteRejectedUploads()) {
            try {
                $filesystem->getDriver()->delete($path);
            } catch (FilesystemException $exception) {
                $previous = $exception;
            }
        }

        throw UploadTooLargeException::forUpload($maximumSize, $path, $size, $previous);
    }
}
