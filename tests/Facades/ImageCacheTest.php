<?php

namespace Biigle\ImageCache\Tests\Facades;

use ImageCache;
use Biigle\ImageCache\GenericImage;
use Biigle\ImageCache\Tests\TestCase;
use Biigle\ImageCache\ImageCache as BaseImageCache;
use Biigle\ImageCache\Facades\ImageCache as ImageCacheFacade;

class ImageCacheTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();
        if (!class_exists(ImageCache::class)) {
            class_alias(ImageCacheFacade::class, 'ImageCache');
        }
    }

    public function testFacade()
    {
        $this->assertInstanceOf(BaseImageCache::class, ImageCache::getFacadeRoot());
    }

    public function testFake()
    {
        ImageCache::fake();
        $image = new GenericImage(1, 'https://example.com/image.jpg');
        $path = ImageCache::get($image, function ($image, $path) {
            return $path;
        });

        $this->assertFalse($this->app['files']->exists($path));
    }
}
