<?php

namespace Biigle\FileCache\Tests;

use Mockery;
use Exception;
use Biigle\FileCache\FileCache;
use Biigle\FileCache\GenericFile;
use Biigle\FileCache\Contracts\File;

class FileCacheTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->cachePath = sys_get_temp_dir().'/biigle_file_cache_test';
        $this->diskPath = sys_get_temp_dir().'/biigle_file_cache_disk';
        $this->noop = function ($file, $path) {
            return $path;
        };
        $this->app['files']->makeDirectory($this->cachePath, 0755, false, true);
        $this->app['files']->makeDirectory($this->diskPath, 0755, false, true);

        config(['filesystems.disks.test' => [
            'driver' => 'local',
            'root' => $this->diskPath,
        ]]);

        config(['filesystems.disks.fixtures' => [
            'driver' => 'local',
            'root' => __DIR__.'/files',
        ]]);
    }

    public function tearDown(): void
    {
        $this->app['files']->deleteDirectory($this->cachePath);
        $this->app['files']->deleteDirectory($this->diskPath);
        parent::tearDown();
    }

    public function testGetExists()
    {
        $cache = new FileCache(['path' => $this->cachePath]);
        $file = new GenericFile('abc://some/image.jpg');
        $hash = hash('sha256', 'abc://some/image.jpg');

        $path = "{$this->cachePath}/{$hash}";
        $this->assertTrue(touch($path, time() - 1));
        $this->assertNotEquals(time(), fileatime($path));
        $file = $cache->get($file, function ($file, $path) {
            return $file;
        });
        $this->assertInstanceof(File::class, $file);
        clearstatcache();
        $this->assertEquals(time(), fileatime($path));
    }

    public function testGetRemote()
    {
        $file = new GenericFile('https://files/image.jpg');
        $hash = hash('sha256', 'https://files/image.jpg');
        $cache = new FileCacheStub(['path' => $this->cachePath]);

        $cache->stream = fopen(__DIR__.'/files/test-image.jpg', 'r');
        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/{$hash}"));
        $path = $cache->get($file, $this->noop);
        $this->assertEquals("{$this->cachePath}/{$hash}", $path);
        $this->assertTrue($this->app['files']->exists("{$this->cachePath}/{$hash}"));
        $this->assertFalse(is_resource($cache->stream));
    }

    public function testGetRemoteTooLarge()
    {
        $file = new GenericFile('https://files/image.jpg');
        $cache = new FileCacheStub([
            'path' => $this->cachePath,
            'max_file_size' => 1,
        ]);

        $cache->stream = fopen(__DIR__.'/files/test-image.jpg', 'r');
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('file is too large');
        $cache->get($file, $this->noop);
    }

    public function testGetDiskDoesNotExist()
    {
        $file = new GenericFile('abc://files/image.jpg');
        $cache = new FileCache(['path' => $this->cachePath]);

        try {
            $cache->get($file, $this->noop);
            $this->fail('Expected an Exception to be thrown.');
        } catch (Exception $e) {
            $this->assertStringContainsString("disk 'abc' does not exist", $e->getMessage());
        }
    }

    public function testGetDiskLocal()
    {
        $this->app['files']->put("{$this->diskPath}/test-image.jpg", 'abc');
        $file = new GenericFile('test://test-image.jpg');
        $cache = new FileCache(['path' => $this->cachePath]);

        $path = $cache->get($file, $this->noop);
        $this->assertEquals($this->diskPath.'/test-image.jpg', $path);
    }

    public function testGetDiskLocalDoesNotExist()
    {
        $file = new GenericFile('test://test-image.jpg');
        $cache = new FileCache(['path' => $this->cachePath]);

        try {
            $cache->get($file, $this->noop);
            $this->fail('Expected an Exception to be thrown.');
        } catch (Exception $e) {
            $this->assertStringContainsString("File does not exist.", $e->getMessage());
        }
    }

    public function testGetDiskCloud()
    {
        config(['filesystems.disks.s3' => ['driver' => 's3']]);
        $file = new GenericFile('s3://files/test-image.jpg');
        $hash = hash('sha256', 's3://files/test-image.jpg');

        $stream = fopen(__DIR__.'/files/test-image.jpg', 'r');

        $mock = Mockery::mock();
        $mock->shouldReceive('disk')->once()->with('s3')->andReturn($mock);
        $mock->shouldReceive('getDriver')->once()->andReturn($mock);
        $mock->shouldReceive('getAdapter')->once()->andReturn($mock);
        $mock->shouldReceive('readStream')->once()->andReturn($stream);
        $this->app['filesystem'] = $mock;

        $cache = new FileCache(['path' => $this->cachePath]);

        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/{$hash}"));
        $path = $cache->get($file, $this->noop);
        $this->assertEquals("{$this->cachePath}/{$hash}", $path);
        $this->assertTrue($this->app['files']->exists("{$this->cachePath}/{$hash}"));
        $this->assertFalse(is_resource($stream));
    }

    public function testGetDiskCloudTooLarge()
    {
        config(['filesystems.disks.s3' => ['driver' => 's3']]);
        $file = new GenericFile('s3://files/test-image.jpg');
        $hash = hash('sha256', 's3://files/test-image.jpg');

        $stream = fopen(__DIR__.'/files/test-image.jpg', 'r');

        $mock = Mockery::mock();
        $mock->shouldReceive('disk')->once()->with('s3')->andReturn($mock);
        $mock->shouldReceive('getDriver')->once()->andReturn($mock);
        $mock->shouldReceive('getAdapter')->once()->andReturn($mock);
        $mock->shouldReceive('readStream')->once()->andReturn($stream);
        $this->app['filesystem'] = $mock;

        $cache = new FileCache([
            'path' => $this->cachePath,
            'max_file_size' => 1,
        ]);

        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/{$hash}"));
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('file is too large');
        $path = $cache->get($file, $this->noop);
    }

    public function testGetOnce()
    {
        $file = new GenericFile('test://test-image.jpg');
        $hash = hash('sha256', 'test://test-image.jpg');
        touch("{$this->cachePath}/{$hash}");
        $this->assertTrue($this->app['files']->exists("{$this->cachePath}/{$hash}"));
        $cache = new FileCache(['path' => $this->cachePath]);
        $file = $cache->getOnce($file, function ($file, $path) {
            return $file;
        });
        $this->assertInstanceof(File::class, $file);
        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/{$hash}"));
    }

    public function testGetStreamCached()
    {
        $file = new GenericFile('test://test-image.jpg');
        $hash = hash('sha256', 'test://test-image.jpg');

        $path = "{$this->cachePath}/{$hash}";
        touch($path, time() - 1);

        $cache = new FileCacheStub(['path' => $this->cachePath]);
        $cache->stream = 'abc123';
        $this->assertNotEquals(time(), fileatime($path));
        $this->assertEquals('abc123', $cache->getStream($file));
        clearstatcache();
        $this->assertEquals(time(), fileatime($path));
    }

    public function testGetStreamRemote()
    {
        $file = new GenericFile('https://files/test-image.jpg');
        $cache = new FileCacheStub(['path' => $this->cachePath]);
        $cache->stream = 'abc123';
        $this->assertEquals('abc123', $cache->getStream($file));
    }

    public function testGetStreamDisk()
    {
        $storage = $this->app['filesystem'];
        $storage->disk('test')->put('files/test.txt', 'test123');
        $file = new GenericFile('test://files/test.txt');
        $cache = new FileCache(['path' => $this->cachePath]);

        $stream = $cache->getStream($file);
        $this->assertTrue(is_resource($stream));
        fclose($stream);
    }

    public function testBatch()
    {
        $this->app['files']->put("{$this->diskPath}/test-image.jpg", 'abc');
        $file = new GenericFile('test://test-image.jpg');
        $file2 = new GenericFile('test://test-image.jpg');

        $cache = new FileCache(['path' => $this->cachePath]);
        $paths = $cache->batch([$file, $file2], function ($files, $paths) {
            return $paths;
        });

        $this->assertCount(2, $paths);
        $this->assertStringContainsString('test-image.jpg', $paths[0]);
        $this->assertStringContainsString('test-image.jpg', $paths[1]);
    }

    public function testBatchOnce()
    {
        $file = new GenericFile('test://test-image.jpg');
        $hash = hash('sha256', 'test://test-image.jpg');
        touch("{$this->cachePath}/{$hash}");
        $this->assertTrue($this->app['files']->exists("{$this->cachePath}/{$hash}"));
        (new FileCache(['path' => $this->cachePath]))->batchOnce([$file], $this->noop);
        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/{$hash}"));
    }

    public function testPrune()
    {
        $this->app['files']->put("{$this->cachePath}/abc", 'abc');
        touch("{$this->cachePath}/abc", time() - 1);
        $this->app['files']->put("{$this->cachePath}/def", 'def');

        $cache = new FileCache([
            'path' => $this->cachePath,
            'max_size' => 3,
        ]);
        $cache->prune();
        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/abc"));
        $this->assertTrue($this->app['files']->exists("{$this->cachePath}/def"));

        $cache = new FileCache([
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

        $cache = new FileCache([
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
        (new FileCache(['path' => $this->cachePath]))->clear();
        fclose($handle);
        $this->assertTrue($this->app['files']->exists("{$this->cachePath}/def"));
        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/abc"));
    }

    public function testMimeTypeWhitelist()
    {
        $cache = new FileCache([
            'path' => $this->cachePath,
            'mime_types' => ['image/jpeg'],
        ]);
        $cache->get(new GenericFile('fixtures://test-image.jpg'), $this->noop);

        try {
            $cache->get(new GenericFile('fixtures://test-file.txt'), $this->noop);
            $this->fail('Expected an Exception to be thrown.');
        } catch (Exception $e) {
            $this->assertStringContainsString("type 'text/plain' not allowed", $e->getMessage());
        }
    }

    public function testExistsDisk()
    {
        $file = new GenericFile('test://test-image.jpg');
        $cache = new FileCache(['path' => $this->cachePath]);

        $this->assertFalse($cache->exists($file));
        $this->app['files']->put("{$this->diskPath}/test-image.jpg", 'abc');
        $this->assertTrue($cache->exists($file));
    }

    public function testExistsDiskTooLarge()
    {
        $this->app['files']->put("{$this->diskPath}/test-image.jpg", 'abc');
        $file = new GenericFile('test://test-image.jpg');
        $cache = new FileCache([
            'path' => $this->cachePath,
            'max_file_size' => 1,
        ]);

        try {
            $cache->exists($file);
            $this->fail('Expected an Exception to be thrown.');
        } catch (Exception $e) {
            $this->assertStringContainsString("too large", $e->getMessage());
        }
    }

    public function testExistsDiskMimeNotAllowed()
    {
        $this->app['files']->put("{$this->diskPath}/test-image.jpg", 'abc');
        $file = new GenericFile('test://test-image.jpg');
        $cache = new FileCache([
            'path' => $this->cachePath,
            'mime_types' => ['image/jpeg'],
        ]);

        try {
            $cache->exists($file);
            $this->fail('Expected an Exception to be thrown.');
        } catch (Exception $e) {
            $this->assertStringContainsString("type 'text/plain' not allowed", $e->getMessage());
        }
    }

    public function testExistsRemote404()
    {
        $file = new GenericFile('https://httpbin.org/status/404');
        $cache = new FileCache(['path' => $this->cachePath]);
        $this->assertFalse($cache->exists($file));
    }

    public function testExistsRemote500()
    {
        $file = new GenericFile('https://httpbin.org/status/500');
        $cache = new FileCache(['path' => $this->cachePath]);
        $this->assertFalse($cache->exists($file));
    }

    public function testExistsRemote200()
    {
        $file = new GenericFile('https://httpbin.org/status/200');
        $cache = new FileCache(['path' => $this->cachePath]);
        $this->assertTrue($cache->exists($file));
    }

    public function testExistsRemoteTooLarge()
    {
        $file = new GenericFile('https://httpbin.org/get');
        $cache = new FileCache([
            'path' => $this->cachePath,
            'max_file_size' => 1,
        ]);

        try {
            $cache->exists($file);
            $this->fail('Expected an Exception to be thrown.');
        } catch (Exception $e) {
            $this->assertStringContainsString("too large", $e->getMessage());
        }
    }

    public function testExistsRemoteMimeNotAllowed()
    {
        $file = new GenericFile('https://httpbin.org/json');
        $cache = new FileCache([
            'path' => $this->cachePath,
            'mime_types' => ['image/jpeg'],
        ]);

        try {
            $cache->exists($file);
            $this->fail('Expected an Exception to be thrown.');
        } catch (Exception $e) {
            $this->assertStringContainsString("type 'application/json' not allowed", $e->getMessage());
        }
    }
}

class FileCacheStub extends FileCache
{
    const MAX_RETRIES = 1;
    public $stream = null;

    protected function getFileStream($url, $context = null)
    {
        if (is_null($this->stream)) {
            return parent::getFileStream($url, $context);
        }

        return $this->stream;
    }
}
