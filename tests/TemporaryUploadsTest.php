<?php

declare(strict_types=1);

use Aws\Api\DateTimeResult;
use Aws\Command;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use HosmelQ\TemporaryUploads\Exceptions\UploadExpiredException;
use HosmelQ\TemporaryUploads\Exceptions\UploadTooLargeException;
use HosmelQ\TemporaryUploads\Facades\TemporaryUploads;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToDeleteFile;

beforeEach(function (): void {
    $this->freezeTime();
    $this->storage = $this->mockStorage();
    $this->path = 'tmp/'.Str::ulid().'/invoice.pdf';
});

it('creates unique signed PUT instructions with the original filename and configured lifetime', function (): void {
    Config::set('temporary-uploads.url_expiration', 300);

    $upload = TemporaryUploads::create('Résumé Final.PDF');
    $next = TemporaryUploads::create('Résumé Final.PDF');
    $parts = explode('/', $upload->path);
    parse_str(parse_url($upload->url, PHP_URL_QUERY), $query);

    expect($upload->path)->toStartWith('tmp/')->toEndWith('/resume-final.pdf')
        ->not->toBe($next->path)
        ->and(Str::isUlid($parts[1]))->toBeTrue()
        ->and($upload->expiresAt->equalTo(CarbonImmutable::now()->addSeconds(300)))->toBeTrue()
        ->and($upload->url)->toStartWith('https://storage.example.test/test-bucket/application/'.$upload->path)
        ->and($upload->headers['x-amz-meta-original-name'])->toBe([base64_encode('Résumé Final.PDF')])
        ->and($query['X-Amz-SignedHeaders'])->toContain('x-amz-meta-original-name')
        ->and($this->storage->getLastCommand())->toBeNull();
});

it('normalizes filenames without introducing directories', function (string $name, string $filename): void {
    expect(TemporaryUploads::create($name)->path)->toEndWith('/'.$filename);
})->with([
    'backslash' => ['C:\\folder\\A File.pdf', 'a-file.pdf'],
    'no transliteration' => ['🧾.pdf', 'file.pdf'],
    'path' => ['../../A File.pdf', 'a-file.pdf'],
    'without extension' => ['A File', 'a-file'],
]);

it('rejects unusable filenames before generating instructions', function (string $name): void {
    expect(fn () => TemporaryUploads::create($name))->toThrow(InvalidArgumentException::class);
})->with(['', '.', '..', "bad\0name.pdf", "\xFF.pdf", str_repeat('a', 1025), str_repeat('é', 800)]);

it('retrieves metadata in one request without downloading the file', function (): void {
    $lastModified = CarbonImmutable::now()->subMinute();
    $this->storage->append(new Result([
        'ContentLength' => 1024,
        'LastModified' => $lastModified,
        'Metadata' => ['original-name' => base64_encode('Factura original.pdf')],
    ]));

    $file = TemporaryUploads::retrieve($this->path);
    $command = $this->storage->getLastCommand();

    expect($file->disk)->toBe('uploads')
        ->and($file->filename)->toBe('invoice.pdf')
        ->and($file->lastModified->equalTo($lastModified))->toBeTrue()
        ->and($file->originalName)->toBe('Factura original.pdf')
        ->and($file->path)->toBe($this->path)
        ->and($file->size)->toBe(1024)
        ->and($command->getName())->toBe('HeadObject')
        ->and($command['Bucket'])->toBe('test-bucket')
        ->and($command['Key'])->toBe('application/'.$this->path);
});

it('accepts uploads without usable original-name metadata', function (array $metadata): void {
    $this->storage->append(new Result([
        'ContentLength' => 0,
        'LastModified' => CarbonImmutable::now(),
        'Metadata' => $metadata,
    ]));

    expect(TemporaryUploads::retrieve($this->path)->originalName)->toBeNull();
})->with([
    'absent' => [[]],
    'empty' => [['original-name' => '']],
    'invalid base64' => [['original-name' => '%invalid']],
    'invalid utf8' => [['original-name' => base64_encode("\xFF")]],
]);

it('accepts the size limit and isolates per-call overrides', function (): void {
    Config::set('temporary-uploads.max_size', 10);
    $this->storage->append(
        new Result(['ContentLength' => 10, 'LastModified' => CarbonImmutable::now()]),
        new Result(['ContentLength' => 11, 'LastModified' => CarbonImmutable::now()]),
        new Result(['ContentLength' => 11, 'LastModified' => CarbonImmutable::now()]),
    );

    expect(TemporaryUploads::retrieve($this->path)->size)->toBe(10)
        ->and(TemporaryUploads::maxSize(11)->retrieve($this->path)->size)->toBe(11)
        ->and(fn () => TemporaryUploads::retrieve($this->path))->toThrow(UploadTooLargeException::class)
        ->and(config('temporary-uploads.max_size'))->toBe(10)
        ->and($this->storage->getLastCommand()->getName())->toBe('HeadObject');
});

it('allows an empty file with a zero-byte override', function (): void {
    $this->storage->append(new Result(['ContentLength' => 0, 'LastModified' => CarbonImmutable::now()]));

    expect(TemporaryUploads::maxSize(0)->retrieve($this->path)->size)->toBe(0);
});

it('deletes oversized uploads when configured and retains the size error if deletion fails', function (bool $fails): void {
    Config::set('temporary-uploads.delete_rejected_uploads', true);
    $this->storage->append(
        new Result(['ContentLength' => 11, 'LastModified' => CarbonImmutable::now()]),
        $fails
            ? new S3Exception('Denied', new Command('DeleteObject'), ['response' => new Response(403)])
            : new Result(),
    );

    expect(fn () => TemporaryUploads::maxSize(10)->retrieve($this->path))
        ->toThrow(function (UploadTooLargeException $exception) use ($fails): void {
            expect($exception->maximumSize)->toBe(10)
                ->and($exception->getMessage())->toBe("Temporary upload [{$this->path}] is 11 bytes, exceeding the 10-byte limit.")
                ->and($exception->path)->toBe($this->path)
                ->and($exception->size)->toBe(11);

            if ($fails) {
                expect($exception->getPrevious())->toBeInstanceOf(UnableToDeleteFile::class);
            } else {
                expect($exception->getPrevious())->toBeNull();
            }
        })
        ->and($this->storage->getLastCommand()->getName())->toBe('DeleteObject')
        ->and($this->storage->getLastCommand()['Key'])->toBe('application/'.$this->path);
})->with([false, true]);

it('rejects uploads at the retention boundary before cleanup', function (): void {
    Config::set('temporary-uploads.retention', 60);
    $this->storage->append(new Result([
        'ContentLength' => 1,
        'LastModified' => CarbonImmutable::now()->subSeconds(60),
    ]));

    expect(fn () => TemporaryUploads::retrieve($this->path))
        ->toThrow(function (UploadExpiredException $exception): void {
            expect($exception->path)->toBe($this->path)
                ->and($exception->getMessage())->toBe("Temporary upload [{$this->path}] has expired.");
        });
});

it('accepts uploads until the retention boundary', function (): void {
    Config::set('temporary-uploads.retention', 60);
    $this->storage->append(new Result([
        'ContentLength' => 1,
        'LastModified' => CarbonImmutable::now()->subSeconds(59),
    ]));

    expect(TemporaryUploads::retrieve($this->path)->size)->toBe(1);
});

it('translates missing uploads while preserving storage failures', function (int $status, string $exception): void {
    $this->storage->append(new S3Exception('Failed', new Command('HeadObject'), [
        'response' => new Response($status),
    ]));

    expect(fn () => TemporaryUploads::retrieve($this->path))->toThrow($exception);
})->with([
    'forbidden' => [403, S3Exception::class],
    'missing' => [404, FileNotFoundException::class],
    'unavailable' => [503, S3Exception::class],
]);

it('rejects paths outside the temporary upload layout', function (string $path): void {
    expect(fn () => TemporaryUploads::retrieve($path))->toThrow(InvalidArgumentException::class)
        ->and($this->storage->getLastCommand())->toBeNull();
})->with([
    '../tmp/01ARZ3NDEKTSV4RRFFQ69G5FAV/file.pdf',
    '/tmp/01ARZ3NDEKTSV4RRFFQ69G5FAV/file.pdf',
    'permanent/01ARZ3NDEKTSV4RRFFQ69G5FAV/file.pdf',
    'tmp/01ARZ3NDEKTSV4RRFFQ69G5FAV/../file.pdf',
    'tmp/01ARZ3NDEKTSV4RRFFQ69G5FAV/nested/file.pdf',
    'tmp/not-a-ulid/file.pdf',
]);

it('rejects unsafe temporary prefixes', function (string $prefix): void {
    Config::set('temporary-uploads.prefix', $prefix);

    expect(fn () => TemporaryUploads::prune())->toThrow(InvalidArgumentException::class);
})->with(['', '/', '/tmp', '../tmp', 'tmp/../permanent', "tmp\0"]);

it('supports nested unicode prefixes and counts storage keys in bytes', function (): void {
    Config::set('temporary-uploads.prefix', 'archivos/recepción/');
    $upload = TemporaryUploads::create('Factura.pdf');
    $this->storage->append(new Result(['ContentLength' => 1, 'LastModified' => CarbonImmutable::now()]));

    expect(TemporaryUploads::retrieve($upload->path)->path)->toBe($upload->path);

    Config::set('temporary-uploads.prefix', str_repeat('é', 500));

    expect(fn () => TemporaryUploads::create('Factura.pdf'))->toThrow(InvalidArgumentException::class);
});

it('rejects invalid duration configuration', function (string $key, int $value, string $method): void {
    Config::set('temporary-uploads.'.$key, $value);

    expect(fn () => TemporaryUploads::{$method}($this->path))->toThrow(InvalidArgumentException::class);
})->with([
    'expired retention' => ['retention', 0, 'retrieve'],
    'negative size' => ['max_size', -1, 'retrieve'],
    'unsigned lifetime' => ['url_expiration', 0, 'create'],
    'unsupported lifetime' => ['url_expiration', 604801, 'create'],
]);

it('rejects negative per-call size limits', function (): void {
    expect(fn () => TemporaryUploads::maxSize(-1))->toThrow(InvalidArgumentException::class);
});

it('requires an S3-compatible disk', function (): void {
    Storage::fake('uploads');

    expect(fn () => TemporaryUploads::create('invoice.pdf'))->toThrow(InvalidArgumentException::class);
});

it('fails when storage cannot supply valid size and modification metadata', function (array $metadata): void {
    $this->storage->append(new Result($metadata));

    expect(fn () => TemporaryUploads::retrieve($this->path))->toThrow(UnexpectedValueException::class);
})->with([
    'missing size' => [['LastModified' => CarbonImmutable::now()]],
    'missing timestamp' => [['ContentLength' => 1]],
    'negative size' => [['ContentLength' => -1, 'LastModified' => CarbonImmutable::now()]],
]);

it('prunes expired uploads across storage pages while preserving other files', function (): void {
    Config::set('temporary-uploads.prefix', 'incoming/files/');
    Config::set('temporary-uploads.retention', 60);
    $expired = 'incoming/files/'.Str::ulid().'/expired.pdf';
    $boundary = 'incoming/files/'.Str::ulid().'/boundary.pdf';
    $fresh = 'incoming/files/'.Str::ulid().'/fresh.pdf';
    $oldTime = new DateTimeResult(CarbonImmutable::now()->subSeconds(60)->toIso8601String());
    $this->storage->append(
        function (CommandInterface $command) use ($expired, $oldTime): Result {
            expect($command['Prefix'])->toBe('application/incoming/files/');

            return new Result([
                'Contents' => [
                    ['Key' => 'application/'.$expired, 'LastModified' => $oldTime, 'Size' => 1],
                    ['Key' => 'application/incoming/files/manual.pdf', 'LastModified' => $oldTime, 'Size' => 1],
                    ['Key' => 'application/incoming/files-other/'.Str::ulid().'/file.pdf', 'LastModified' => $oldTime, 'Size' => 1],
                ],
                'IsTruncated' => true,
                'NextContinuationToken' => 'page-two',
            ]);
        },
        function (CommandInterface $command) use ($expired): Result {
            expect($command->getName())->toBe('DeleteObject')
                ->and($command['Key'])->toBe('application/'.$expired);

            return new Result();
        },
        function (CommandInterface $command) use ($boundary, $fresh, $oldTime): Result {
            expect($command['ContinuationToken'])->toBe('page-two');

            return new Result([
                'Contents' => [
                    ['Key' => 'application/'.$boundary, 'LastModified' => $oldTime, 'Size' => 1],
                    ['Key' => 'application/'.$fresh, 'LastModified' => new DateTimeResult(CarbonImmutable::now()->toIso8601String()), 'Size' => 1],
                ],
                'IsTruncated' => false,
            ]);
        },
        function (CommandInterface $command) use ($boundary): Result {
            expect($command->getName())->toBe('DeleteObject')
                ->and($command['Key'])->toBe('application/'.$boundary);

            return new Result();
        },
    );

    expect(TemporaryUploads::prune())->toBe(2)
        ->and($this->storage)->toBeEmpty();
});
