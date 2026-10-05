<?php

declare(strict_types=1);

use Aws\Api\DateTimeResult;
use Aws\Command;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use League\Flysystem\UnableToDeleteFile;

it('reports the number of deleted temporary uploads', function (): void {
    $storage = $this->mockStorage();
    $storage->append(new Result(['Contents' => [[
        'Key' => 'application/tmp/'.Str::ulid().'/expired.pdf',
        'LastModified' => new DateTimeResult(CarbonImmutable::now()->subDays(2)->toIso8601String()),
        'Size' => 10,
    ]]]), new Result());

    $this->artisan('temporary-uploads:prune')
        ->expectsOutputToContain('Pruned 1 temporary upload(s).')
        ->assertSuccessful();

    expect($storage->getLastCommand()->getName())->toBe('DeleteObject');
});

it('does not report success when storage rejects cleanup', function (): void {
    $storage = $this->mockStorage();
    $storage->append(new Result(['Contents' => [[
        'Key' => 'application/tmp/'.Str::ulid().'/expired.pdf',
        'LastModified' => new DateTimeResult(CarbonImmutable::now()->subDays(2)->toIso8601String()),
        'Size' => 10,
    ]]]), new S3Exception('Denied', new Command('DeleteObject'), ['response' => new Response(403)]));

    $this->artisan('temporary-uploads:prune')->run();
})->throws(UnableToDeleteFile::class);
