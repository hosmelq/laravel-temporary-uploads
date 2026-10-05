<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Delete Rejected Uploads
    |--------------------------------------------------------------------------
    |
    | When enabled, retrieving an upload that exceeds the maximum size attempts
    | to delete it before throwing an exception. When disabled, rejected
    | uploads remain in storage until they are pruned or deleted.
    |
    */

    'delete_rejected_uploads' => false,

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the disk used to store temporary uploads. The disk
    | must use the S3 driver and be defined in your application's filesystem
    | configuration. S3-compatible storage services are also supported.
    |
    */

    'disk' => env('TEMPORARY_UPLOADS_DISK', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Maximum Upload Size
    |--------------------------------------------------------------------------
    |
    | This value sets the maximum accepted file size in bytes. The limit is
    | checked when an upload is retrieved, after it reaches storage. You
    | may override this limit for a retrieval using the maxSize method.
    |
    | Default: 10 MiB (10,485,760 bytes).
    |
    */

    'max_size' => 10 * 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Storage Prefix
    |--------------------------------------------------------------------------
    |
    | Temporary uploads are stored beneath this prefix on the configured disk.
    | Use a non-empty relative path without a leading slash. Each temporary
    | upload receives a unique subdirectory and a normalized filename.
    |
    */

    'prefix' => env('TEMPORARY_UPLOADS_PREFIX', 'tmp'),

    /*
    |--------------------------------------------------------------------------
    | Upload Retention
    |--------------------------------------------------------------------------
    |
    | This value determines how long an upload remains valid, in seconds from
    | its last modification. Expired uploads cannot be retrieved and may be
    | deleted by the temporary-uploads:prune command, which you schedule.
    |
    | Default: 24 hours (86,400 seconds).
    |
    */

    'retention' => 24 * 60 * 60,

    /*
    |--------------------------------------------------------------------------
    | Upload URL Expiration
    |--------------------------------------------------------------------------
    |
    | Here you may configure how long a signed upload URL remains valid, in
    | seconds. This duration must be positive and cannot exceed seven days.
    | Uploaded files are governed separately by the retention setting.
    |
    | Default: 15 minutes (900 seconds).
    |
    */

    'url_expiration' => 15 * 60,

];
