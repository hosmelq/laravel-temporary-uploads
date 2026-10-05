<?php

declare(strict_types=1);

namespace HosmelQ\TemporaryUploads;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class UploadUrl implements Responsable
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public CarbonImmutable $expiresAt,
        public array $headers,
        public string $path,
        public string $url,
    ) {
    }

    /**
     * @param Request $request
     */
    public function toResponse(mixed $request): JsonResponse
    {
        return new JsonResponse($this);
    }
}
