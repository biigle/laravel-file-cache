# Image Cache

Fetch and cache image files from filesystem, cloud storage or public webservers in Laravel or Lumen.

## Installation

```
composer require biigle/laravel-image-cache
```

## Usage

```php
use Biigle\ImageCache\ImageCache;
use Biigle\ImageCache\GenericImage;

$imageId = 1;
$imageUrl = 'https://example.com/images/image.jpg';
// Implements Biigle\ImageCache\Contracts\Image.
$image = new GenericImage($imageId, $imageUrl);

$cache = new ImageCache;
$cache->getOnce($image, function ($image, $path) {
    // do stuff
});
```
