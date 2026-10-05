# Laravel Temporary Uploads

Let clients upload files directly to S3-compatible storage from Laravel
applications, then retrieve the uploaded files and clean up the ones that
expire.

## Requirements

- Laravel 12+
- PHP 8.4+
- S3-compatible Laravel filesystem disk

## Installation

Install Laravel Temporary Uploads with Composer:

```bash
composer require hosmelq/laravel-temporary-uploads
```

The package uses the `s3` disk and stores uploads under the `tmp` prefix by
default. Set `TEMPORARY_UPLOADS_DISK` and `TEMPORARY_UPLOADS_PREFIX` to use
another S3-compatible disk or prefix:

```dotenv
TEMPORARY_UPLOADS_DISK=uploads
TEMPORARY_UPLOADS_PREFIX=temporary
```

The package only handles temporary uploads. Your application provides the
routes, decides who may upload or use a file, and saves the files it wants to
keep.

## Create upload URLs

Create a signed upload URL with the `TemporaryUploads` facade and return it to
the client:

```php
use HosmelQ\TemporaryUploads\Facades\TemporaryUploads;

return TemporaryUploads::create('Invoice 2026.pdf');
```

The result implements Laravel's `Responsable` interface. Returning it from a
route or controller produces JSON with `expiresAt`, `headers`, `path`, and `url`.
The URL expires after 15 minutes by default; `expiresAt` is its expiration time
in ISO 8601 format.

Each upload gets a unique path in the form
`{prefix}/{ulid}/{normalized-filename}`, such as
`tmp/01KA4T9Q7Z3M5X8R2N6P0WJ4VC/invoice-2026.pdf`. Keep the path: it is the
reference used to retrieve the file later.

## Upload files

The client sends the raw file bytes to `url` in a `PUT` request with the
returned headers. Each header value is a list of strings:

```js
await fetch(upload.url, {
    body: file,
    headers: Object.fromEntries(
        Object.entries(upload.headers).map(([name, values]) => [name, values.join(', ')]),
    ),
    method: 'PUT',
});
```

Browsers set `Host` themselves. For browser uploads, configure the bucket's CORS
policy to allow your application's origin, the `PUT` method, and the returned
headers.

## Retrieve temporary files

After the upload finishes, retrieve the file with its path:

```php
use HosmelQ\TemporaryUploads\Facades\TemporaryUploads;

$file = TemporaryUploads::retrieve($path);

$file->filename; // "invoice-2026.pdf"
$file->originalName; // "Invoice 2026.pdf"
$file->size; // 48213
```

The returned file also provides its `disk`, `path`, and `lastModified` time.
`originalName` is the filename passed to `create()`, or `null` when storage did
not keep it.

Retrieval reads the file's metadata without downloading its contents. It only
accepts paths created by this package under the configured prefix, and it
rejects files that are too large or have expired.

A path does not prove who uploaded a file. Check that the current user may use
it before retrieving a path received from a client.

## Read file contents

Read the complete file into memory with `contents()`:

```php
$contents = $file->contents();
```

Use `readStream()` for larger files, and close the stream when you are done.
This example saves the upload to a permanent location:

```php
use Illuminate\Support\Facades\Storage;

$stream = $file->readStream();

Storage::disk('s3')->writeStream("invoices/{$file->filename}", $stream);

fclose($stream);
```

Save the files you want to keep outside the temporary prefix. Holding on to a
path does not stop an upload from expiring.

The size and expiration checks describe the file at the moment it was
retrieved. The upload URL can overwrite the file until the URL expires, so read
the contents right after retrieving it.

## Limit file size

Retrieval rejects files larger than 10 MiB by default. Use `maxSize()` to set a
different limit in bytes for a single call:

```php
use HosmelQ\TemporaryUploads\Facades\TemporaryUploads;

$file = TemporaryUploads::maxSize(20 * 1024 * 1024)->retrieve($path);
```

`maxSize()` does not change the configured limit or affect later calls.

The limit is checked when the file is retrieved, not while it uploads, so an
oversized file can still reach storage. Set `delete_rejected_uploads` to `true`
to delete oversized files as soon as they are rejected. Otherwise they stay in
storage until they expire and are pruned.

## Delete expired uploads

Uploads expire 24 hours after the last-modified time reported by storage.
Expired uploads can no longer be retrieved, even if they have not been deleted
yet.

Delete expired uploads with the `temporary-uploads:prune` command:

```bash
php artisan temporary-uploads:prune
```

The package does not schedule the command. Schedule it in your application:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('temporary-uploads:prune')->hourly()->withoutOverlapping();
```

The command only deletes expired files created by this package under the
configured prefix.

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=temporary-uploads-config
```

Durations are in seconds and sizes are in bytes:

| Option | Default | Description |
| --- | --- | --- |
| `delete_rejected_uploads` | `false` | Delete oversized uploads when they are rejected. |
| `disk` | `s3` | S3-compatible disk that stores the uploads. |
| `max_size` | `10485760` | Largest file that can be retrieved. |
| `prefix` | `tmp` | Storage prefix reserved for temporary uploads. |
| `retention` | `86400` | Time an upload remains retrievable after it was last modified. |
| `url_expiration` | `900` | Time an upload URL remains valid, up to seven days. |

## Use dependency injection

Inject `TemporaryUploads` when a class should not depend on the facade:

```php
use HosmelQ\TemporaryUploads\TemporaryUploads;
use HosmelQ\TemporaryUploads\UploadUrl;

final class CreateTemporaryUpload
{
    public function __construct(private TemporaryUploads $uploads) {}

    public function handle(string $filename): UploadUrl
    {
        return $this->uploads->create($filename);
    }
}
```

## Handle errors

Retrieval throws an exception when the file is missing, has expired, or is too
large:

```php
use HosmelQ\TemporaryUploads\Exceptions\UploadExpiredException;
use HosmelQ\TemporaryUploads\Exceptions\UploadTooLargeException;
use HosmelQ\TemporaryUploads\Facades\TemporaryUploads;
use Illuminate\Contracts\Filesystem\FileNotFoundException;

try {
    $file = TemporaryUploads::retrieve($path);
} catch (FileNotFoundException $exception) {
    // The file was never uploaded or has already been deleted.
} catch (UploadExpiredException $exception) {
    // The file is older than the retention period.
} catch (UploadTooLargeException $exception) {
    // $exception->size exceeds $exception->maximumSize.
}
```

`UploadTooLargeException` is thrown whether or not `delete_rejected_uploads` is
enabled. If deleting the file fails, the storage error is available through
`getPrevious()`.

`create()` and `retrieve()` throw an `InvalidArgumentException` for invalid
input, such as a filename with control characters or a path outside the
configured prefix. Other storage errors are not caught.

## Development

Run the test suite with:

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a list of changes.

## Contributing

Pull requests are welcome. Please run the test suite before submitting changes.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
