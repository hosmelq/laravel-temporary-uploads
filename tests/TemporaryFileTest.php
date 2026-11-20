<?php

declare(strict_types=1);

use Aws\Command;
use Aws\Exception\CredentialsException;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use HosmelQ\TemporaryUploads\Facades\TemporaryUploads;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToReadFile;

beforeEach(function (): void {
    $this->storage = $this->mockStorage();
    $this->path = 'tmp/'.Str::ulid().'/file.txt';

    $this->storage->append(new Result([
        'ContentLength' => 18,
        'ETag' => '"validated"',
        'LastModified' => CarbonImmutable::now(),
    ]));
});

it('reads only the version of the upload that was retrieved', function (): void {
    $this->storage->append(
        new Result(['Body' => Utils::streamFor('temporary contents')]),
        new Result(['Body' => Utils::streamFor('temporary contents')]),
    );

    $file = TemporaryUploads::retrieve($this->path);

    expect($file->contents())->toBe('temporary contents')
        ->and($this->storage->getLastCommand()->getName())->toBe('GetObject')
        ->and($this->storage->getLastCommand()['IfMatch'])->toBe('"validated"')
        ->and($this->storage->getLastCommand()['Key'])->toBe('application/'.$this->path);

    $stream = $file->readStream();

    try {
        expect($stream)->toBeResource()
            ->and(stream_get_meta_data($stream)['wrapper_type'])->toBe('PHP')
            ->and(stream_get_contents($stream))->toBe('temporary contents')
            ->and($this->storage->getLastCommand()['IfMatch'])->toBe('"validated"');
    } finally {
        fclose($stream);
    }
});

it('fails to read an upload that was replaced or removed after retrieval', function (
    string $method,
    int $status,
): void {
    $this->storage->append(new S3Exception('Failed', new Command('GetObject'), [
        'response' => new Response($status),
    ]));

    $file = TemporaryUploads::retrieve($this->path);

    expect(fn () => $file->{$method}())->toThrow(UnableToReadFile::class);
})->with(['contents', 'readStream'])->with(['missing' => 404, 'replaced' => 412]);

it('reads with the disk options and read mode', function (bool $streamReads): void {
    Config::set('filesystems.disks.uploads.options', ['@http' => ['timeout' => 5]]);
    Config::set('filesystems.disks.uploads.stream_reads', $streamReads);
    Storage::forgetDisk('uploads');

    $this->storage->append(
        new Result(['Body' => Utils::streamFor('temporary contents')]),
        new Result(['Body' => Utils::streamFor('temporary contents')]),
    );

    $file = TemporaryUploads::retrieve($this->path);

    $file->contents();

    expect($this->storage->getLastCommand()['@http']['timeout'])->toBe(5)
        ->and($this->storage->getLastCommand()['@http'])->not->toHaveKey('stream');

    fclose($file->readStream());

    expect($this->storage->getLastCommand()['@http']['timeout'])->toBe(5)
        ->and($this->storage->getLastCommand()['@http']['stream'] ?? false)->toBe($streamReads)
        ->and($this->storage->getLastCommand()['IfMatch'])->toBe('"validated"');
})->with(['buffered reads' => false, 'stream reads' => true]);

it('reports SDK failures other than storage errors as unreadable', function (string $method): void {
    $this->storage->append(new CredentialsException('The credentials could not be refreshed.'));

    $file = TemporaryUploads::retrieve($this->path);

    expect(fn () => $file->{$method}())->toThrow(UnableToReadFile::class);
})->with(['contents', 'readStream']);
