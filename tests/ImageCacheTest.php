<?php

namespace Biigle\ImageCache\Tests;

use Mockery;
use Exception;
use Biigle\ImageCache\ImageCache;
use Biigle\ImageCache\GenericImage;

class ImageCacheTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();
        $this->cachePath = sys_get_temp_dir().'/biigle_image_cache_test';
        $this->diskPath = sys_get_temp_dir().'/biigle_image_cache_disk';
        $this->noop = function ($image, $path) {
            return $path;
        };
        $this->app['files']->makeDirectory($this->cachePath, 0755, false, true);

        config(['filesystems.disks.test' => [
            'driver' => 'local',
            'root' => $this->diskPath,
        ]]);
    }

    public function tearDown()
    {
        $this->app['files']->deleteDirectory($this->cachePath);
        $this->app['files']->deleteDirectory($this->diskPath);
        parent::tearDown();
    }

    public function testGetExists()
    {
        $cache = new ImageCache(['path' => $this->cachePath]);
        $image = new GenericImage('abc://some/image.jpg');
        $hash = hash('sha256', 'abc://some/image.jpg');

        $path = "{$this->cachePath}/{$hash}";
        $this->assertTrue(touch($path, time() - 1));
        $this->assertNotEquals(time(), fileatime($path));
        $cache->get($image, $this->noop);
        clearstatcache();
        $this->assertEquals(time(), fileatime($path));
    }

    public function testGetRemote()
    {
        $image = new GenericImage('https://files/image.jpg');
        $hash = hash('sha256', 'https://files/image.jpg');
        $cache = new ImageCacheStub(['path' => $this->cachePath]);

        $cache->stream = fopen(__DIR__.'/files/test-image.jpg', 'r');
        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/{$hash}"));
        $path = $cache->get($image, $this->noop);
        $this->assertEquals("{$this->cachePath}/{$hash}", $path);
        $this->assertTrue($this->app['files']->exists("{$this->cachePath}/{$hash}"));
        $this->assertFalse(is_resource($cache->stream));
    }

    public function testGetRemoteTooLarge()
    {
        $image = new GenericImage('https://files/image.jpg');
        $cache = new ImageCacheStub([
            'path' => $this->cachePath,
            'max_image_size' => 1,
        ]);

        $cache->stream = fopen(__DIR__.'/files/test-image.jpg', 'r');
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('file is too large');
        $cache->get($image, $this->noop);
    }

    public function testGetDiskDoesNotExist()
    {
        $image = new GenericImage('abc://files/image.jpg');
        $cache = new ImageCache(['path' => $this->cachePath]);

        try {
            $cache->get($image, $this->noop);
            $this->assertTrue(false);
        } catch (Exception $e) {
            $this->assertContains("disk 'abc' does not exist", $e->getMessage());
        }
    }

    public function testGetDiskLocal()
    {
        $image = new GenericImage('test://test-image.jpg');
        $cache = new ImageCache(['path' => $this->cachePath]);

        $path = $cache->get($image, $this->noop);
        $this->assertEquals($this->diskPath.'/test-image.jpg', $path);
    }

    public function testGetDiskCloud()
    {
        config(['filesystems.disks.s3' => ['driver' => 's3']]);
        $image = new GenericImage('s3://files/test-image.jpg');
        $hash = hash('sha256', 's3://files/test-image.jpg');

        $stream = fopen(__DIR__.'/files/test-image.jpg', 'r');

        $mock = Mockery::mock();
        $mock->shouldReceive('disk')->once()->with('s3')->andReturn($mock);
        $mock->shouldReceive('getDriver')->once()->andReturn($mock);
        $mock->shouldReceive('getAdapter')->once()->andReturn($mock);
        $mock->shouldReceive('readStream')->once()->andReturn($stream);
        $this->app['filesystem'] = $mock;

        $cache = new ImageCache(['path' => $this->cachePath]);

        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/{$hash}"));
        $path = $cache->get($image, $this->noop);
        $this->assertEquals("{$this->cachePath}/{$hash}", $path);
        $this->assertTrue($this->app['files']->exists("{$this->cachePath}/{$hash}"));
        $this->assertFalse(is_resource($stream));
    }

    public function testGetDiskCloudTooLarge()
    {
        config(['filesystems.disks.s3' => ['driver' => 's3']]);
        $image = new GenericImage('s3://files/test-image.jpg');
        $hash = hash('sha256', 's3://files/test-image.jpg');

        $stream = fopen(__DIR__.'/files/test-image.jpg', 'r');

        $mock = Mockery::mock();
        $mock->shouldReceive('disk')->once()->with('s3')->andReturn($mock);
        $mock->shouldReceive('getDriver')->once()->andReturn($mock);
        $mock->shouldReceive('getAdapter')->once()->andReturn($mock);
        $mock->shouldReceive('readStream')->once()->andReturn($stream);
        $this->app['filesystem'] = $mock;

        $cache = new ImageCache([
            'path' => $this->cachePath,
            'max_image_size' => 1,
        ]);

        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/{$hash}"));
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('file is too large');
        $path = $cache->get($image, $this->noop);
    }

    public function testGetOnce()
    {
        $image = new GenericImage('test://test-image.jpg');
        $hash = hash('sha256', 'test://test-image.jpg');
        touch("{$this->cachePath}/{$hash}");
        $this->assertTrue($this->app['files']->exists("{$this->cachePath}/{$hash}"));
        (new ImageCache(['path' => $this->cachePath]))->getOnce($image, $this->noop);
        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/{$hash}"));
    }

    public function testGetStreamCached()
    {
        $image = new GenericImage('test://test-image.jpg');
        $hash = hash('sha256', 'test://test-image.jpg');

        $path = "{$this->cachePath}/{$hash}";
        touch($path, time() - 1);

        $cache = new ImageCacheStub(['path' => $this->cachePath]);
        $cache->stream = 'abc123';
        $this->assertNotEquals(time(), fileatime($path));
        $this->assertEquals('abc123', $cache->getStream($image));
        clearstatcache();
        $this->assertEquals(time(), fileatime($path));
    }

    public function testGetStreamRemote()
    {
        $image = new GenericImage('https://files/test-image.jpg');
        $cache = new ImageCacheStub(['path' => $this->cachePath]);
        $cache->stream = 'abc123';
        $this->assertEquals('abc123', $cache->getStream($image));
    }

    public function testGetStreamDisk()
    {
        $storage = $this->app['filesystem'];
        $storage->disk('test')->put('files/test.txt', 'test123');
        $image = new GenericImage('test://files/test.txt');
        $cache = new ImageCache(['path' => $this->cachePath]);

        $stream = $cache->getStream($image);
        $this->assertTrue(is_resource($stream));
        fclose($stream);
    }

    public function testBatch()
    {
        $image = new GenericImage('test://test-image.jpg');
        $image2 = new GenericImage('test://test-image.jpg');

        $cache = new ImageCache(['path' => $this->cachePath]);
        $paths = $cache->batch([$image, $image2], function ($images, $paths) {
            return $paths;
        });

        $this->assertCount(2, $paths);
        $this->assertContains('test-image.jpg', $paths[0]);
        $this->assertContains('test-image.jpg', $paths[1]);
    }

    public function testBatchOnce()
    {
        $image = new GenericImage('test://test-image.jpg');
        $hash = hash('sha256', 'test://test-image.jpg');
        touch("{$this->cachePath}/{$hash}");
        $this->assertTrue($this->app['files']->exists("{$this->cachePath}/{$hash}"));
        (new ImageCache(['path' => $this->cachePath]))->batchOnce([$image], $this->noop);
        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/{$hash}"));
    }

    public function testPrune()
    {
        $this->app['files']->put("{$this->cachePath}/abc", 'abc');
        touch("{$this->cachePath}/abc", time() - 1);
        $this->app['files']->put("{$this->cachePath}/def", 'def');

        $cache = new ImageCache([
            'path' => $this->cachePath,
            'max_size' => 3,
        ]);
        $cache->prune();
        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/abc"));
        $this->assertTrue($this->app['files']->exists("{$this->cachePath}/def"));

        $cache = new ImageCache([
            'path' => $this->cachePath,
            'max_size' => 0,
        ]);
        $cache->prune();
        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/def"));
    }

    public function testPruneAge()
    {
        $this->app['files']->put("{$this->cachePath}/abc", 'abc');
        touch("{$this->cachePath}/abc", time() - 61);
        $this->app['files']->put("{$this->cachePath}/def", 'def');

        $cache = new ImageCache([
            'path' => $this->cachePath,
            'max_age' => 1,
        ]);
        $cache->prune();
        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/abc"));
        $this->assertTrue($this->app['files']->exists("{$this->cachePath}/def"));
    }

     public function testClear()
     {
         $this->app['files']->put("{$this->cachePath}/abc", 'abc');
         $this->app['files']->put("{$this->cachePath}/def", 'abc');
         $handle = fopen("{$this->cachePath}/def", 'r');
         flock($handle, LOCK_SH);
         (new ImageCache(['path' => $this->cachePath]))->clear();
         fclose($handle);
         $this->assertTrue($this->app['files']->exists("{$this->cachePath}/def"));
         $this->assertFalse($this->app['files']->exists("{$this->cachePath}/abc"));
     }
}

class ImageCacheStub extends ImageCache
{
    const MAX_RETRIES = 1;
    public $stream = null;

    protected function getImageStream($url, $context = null)
    {
        if (is_null($this->stream)) {
            return parent::getImageStream($url, $context);
        }

        return $this->stream;
    }
}
