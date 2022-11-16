# File Cache


Fetch and cache files from local filesystem, cloud storage or public webservers in Laravel or Lumen.

The file cache is specifically designed for use in concurrent processing with multiple parallel queue workers.

[![Tests](https://github.com/biigle/laravel-file-cache/actions/workflows/tests.yml/badge.svg)](https://github.com/biigle/laravel-file-cache/actions/workflows/tests.yml)

## Installation

```
composer require jackardios/laravel-file-cache
```

### Laravel

The service provider and `FileCache` facade are auto-discovered by Laravel.

### Lumen

Add this to `bootstrap/app.php`:
```php
$app->register(Biigle\FileCache\FileCacheServiceProvider::class);
$app->register(Illuminate\Filesystem\FilesystemServiceProvider::class);
```

To use the `FileCache` facade, enable `$app->withFacades()` and add the following to `bootstrap/app.php`:

```php
if (!class_exists(FileCache::class)) {
    class_alias(Biigle\FileCache\Facades\FileCache::class, 'FileCache');
}
```

Without facades, the file cache instance is available as `app('file-cache')`.

## Usage

Take a look at the [`FileCache`](src/Contracts/FileCache.php) contract to see the public API of the file cache. Example:

```php
use FileCache;
use Biigle\FileCache\GenericFile;

// Implements Biigle\FileCache\Contracts\File.
$file = new GenericFile('https://example.com/images/image.jpg');

FileCache::get($file, function ($file, $path) {
    // do stuff
});
```

If the file URL specifies another protocol than `http` or `https` (e.g. `mydisk://images/image.jpg`), the file cache looks for the file in the appropriate storage disk configured at `filesystems.disks`. You can not use a local file path as URL (e.g. `/vol/images/image.jpg`). Instead, configure a storage disk with the `local` driver.

## Configuration

The file cache comes with a sensible default configuration. You can override it in the `file-cache` namespace or with environment variables.

### file-cache.max_file_size

Default: `-1` (any size)
Environment: `FILE_CACHE_MAX_FILE_SIZE`

Maximum allowed size of a cached file in bytes. Set to `-1` to allow any size.

### file-cache.max_age

Default: `60`
Environment: `FILE_CACHE_MAX_AGE`

Maximum age in minutes of a file in the cache. Older files are pruned.

### file-cache.max_size

Default: `1E+9` (1 GB)
Environment: `FILE_CACHE_MAX_SIZE`

Maximum size (soft limit) of the file cache in bytes. If the cache exceeds this size, old files are pruned.

### file-cache.path

Default: `'storage/framework/cache/files'`

Directory to use for the file cache.

### file-cache.timeout

Default: `5.0`
Environment: `FILE_CACHE_TIMEOUT`

Read timeout in seconds for fetching remote files. If the stream transmits no data for longer than this period (or cannot be established), caching the file fails.

### file-cache.prune_interval

Default `'*/5 * * * *'` (every five minutes)

Interval for the scheduled task to prune the file cache.

### file-cache.mime_types

Default: `[]` (allow all types)

Array of allowed MIME types for cached files. Caching of files with other types will fail.

## Clearing

The file cache is cleared when you call `php artisan cache:clear`.

## Testing

The `FileCache` facade provides a fake for easy testing. The fake does not actually fetch and store any files, but only executes the callback function with a faked file path.

```php
use FileCache;
use Biigle\FileCache\GenericFile;

FileCache::fake();
$file = new GenericFile('https://example.com/image.jpg');
$path = FileCache::get($file, function ($file, $path) {
    return $path;
});

$this->assertFalse($this->app['files']->exists($path));
```
