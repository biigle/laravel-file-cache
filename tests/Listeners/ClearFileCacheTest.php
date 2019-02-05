<?php

namespace Biigle\FileCache\Tests\Listeners;

use Biigle\FileCache\Tests\TestCase;

class ClearFileCacheTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();
        $this->cachePath = sys_get_temp_dir().'/biigle_file_cache_test';
        $this->app['files']->makeDirectory($this->cachePath, 0755, false, true);
    }

    public function tearDown()
    {
        $this->app['files']->deleteDirectory($this->cachePath);
        parent::tearDown();
    }

    public function testListen()
    {
        config(['file-cache.path' => $this->cachePath]);
        $this->app['files']->put($this->cachePath.'/1', 'abc');
        $this->app['events']->dispatch('cache:clearing');
        $this->assertFalse($this->app['files']->exists($this->cachePath.'/1'));
    }
}
