<?php

namespace Biigle\ImageCache\Tests\Listeners;

use Biigle\ImageCache\Tests\TestCase;

class ClearImageCacheTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();
        $this->cachePath = sys_get_temp_dir().'/biigle_image_cache_test';
        $this->app['files']->makeDirectory($this->cachePath, 0755, false, true);
    }

    public function tearDown()
    {
        $this->app['files']->deleteDirectory($this->cachePath);
        parent::tearDown();
    }

    public function testListen()
    {
        config(['image.cache.path' => $this->cachePath]);
        $this->app['files']->put($this->cachePath.'/1', 'abc');
        $this->app['events']->dispatch('cache:clearing');
        $this->assertFalse($this->app['files']->exists($this->cachePath.'/1'));
    }
}
