# Image Cache

Fetch and cache image files from filesystem, cloud storage or public webservers in Laravel or Lumen.

The image cache is specifically designed for use in concurrent image processing with multiple parallel queue workers.

## Installation

```
composer config repositories.image-cache vcs https://github.com/biigle/laravel-image-cache
composer require biigle/laravel-image-cache
```

### Laravel

The service provider and `ImageCache` facade are auto-discovered by Laravel.

### Lumen

Add `$app->register(Biigle\ImageCache\ImageCacheServiceProvider::class);` to `bootstrap/app.php`.

To use the `ImageCache` facade, enable `$app->withFacades()` and add the following to `bootstrap/app.php`:

```php
if (!class_exists(ImageCache::class)) {
    class_alias(Biigle\ImageCache\Facades\ImageCache::class, 'ImageCache');
}
```

Without facades, the image cache instance is available as `app('image-cache')`.

## Usage

```php
use ImageCache;
use Biigle\ImageCache\GenericImage;

// Implements Biigle\ImageCache\Contracts\Image.
$image = new GenericImage('https://example.com/images/image.jpg');

ImageCache::get($image, function ($image, $path) {
    // do stuff
});
```

If the image URL specifies another protocol than `http` or `https` (e.g. `mydisk://images/image.jpg`), the image cache looks for the image in the appropriate storage disk configured at `filesystems.disks`. You can not use a local file path as URL (e.g. `/vol/images/image.jpg`). Instead, configure a storage disk with the `local` driver.

You can also use the `ImageCache` facade to access the image cache.

## Configuration

The image cache comes with a sensible default configuration. You can override it in the `image.cache` namespace or with environment variables.

### image.cache.max_image_size

Default: `1E+8` (100 MB)
Environment: `IMAGE_CACHE_MAX_IMAGE_SIZE`

Maximum allowed size of a cached image in bytes. Set to `-1` to allow any size.

### image.cache.max_age

Default: `60`
Environment: `IMAGE_CACHE_MAX_AGE`

Maximum age in minutes of an image in the cache. Older images are pruned.

### image.cache.max_size

Default: `1E+9` (1 GB)
Environment: `IMAGE_CACHE_MAX_SIZE`

Maximum size (soft limit) of the image cache in bytes. If the cache exceeds this size, old images are pruned.

### image.cache.path

Default: `'storage/framework/cache/images'`

Directory to use for the image cache.

### image.cache.timeout

Default: `5.0`
Environment: `IMAGE_CACHE_TIMEOUT`

Read timeout in seconds for fetching remote images. If the stream transmits no data for longer than this period (or cannot be established), caching the image fails.

### image.cache.prune_interval

Default `'*/5 * * * *'` (every five minutes)

Interval for the scheduled task to prune the image cache.

## Clearing

The image cache is cleared when you call `php artisan cache:clear`.

## Testing

The `ImageCache` facade provides a fake for easy testing. The fake does not actually fetch and store any images, but only executes the callback function with a faked image path.

```php
use ImageCache;
use Biigle\ImageCache\GenericImage;

ImageCache::fake();
$image = new GenericImage('https://example.com/image.jpg');
$path = ImageCache::get($image, function ($image, $path) {
    return $path;
});

$this->assertFalse($this->app['files']->exists($path));
```
