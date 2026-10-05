<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use HosmelQ\TemporaryUploads\UploadUrl;
use Illuminate\Support\Facades\Route;

it('returns upload instructions as JSON directly from a route', function (): void {
    $upload = new UploadUrl(
        expiresAt: CarbonImmutable::parse('2026-10-05T12:15:00-06:00'),
        headers: ['x-amz-meta-original-name' => ['SW52b2ljZS5wZGY=']],
        path: 'tmp/01ARZ3NDEKTSV4RRFFQ69G5FAV/invoice.pdf',
        url: 'https://storage.example.test/upload?signature=test',
    );

    Route::get('/temporary-upload', fn (): UploadUrl => $upload);

    $this->get('/temporary-upload')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson([
            'expiresAt' => '2026-10-05T18:15:00.000000Z',
            'headers' => ['x-amz-meta-original-name' => ['SW52b2ljZS5wZGY=']],
            'path' => 'tmp/01ARZ3NDEKTSV4RRFFQ69G5FAV/invoice.pdf',
            'url' => 'https://storage.example.test/upload?signature=test',
        ]);
});
