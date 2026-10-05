<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use HosmelQ\TemporaryUploads\TemporaryFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;

it('reads content and streams only when requested', function (): void {
    $disk = Storage::fake('uploads');
    $disk->put('tmp/file.txt', 'temporary contents');

    $file = new TemporaryFile('uploads', 'file.txt', $disk, CarbonImmutable::now(), null, 'tmp/file.txt', 18);

    expect($file->contents())->toBe('temporary contents');
    $stream = $file->readStream();

    try {
        expect($stream)->toBeResource()
            ->and(stream_get_contents($stream))->toBe('temporary contents');
    } finally {
        fclose($stream);
    }
});

it('reports when an upload disappears before its content is read', function (string $method): void {
    $disk = Storage::fake('uploads');
    $file = new TemporaryFile('uploads', 'file.txt', $disk, CarbonImmutable::now(), null, 'tmp/missing.txt', 0);

    expect(fn () => $file->{$method}())->toThrow(UnableToReadFile::class);
})->with(['contents', 'readStream']);
