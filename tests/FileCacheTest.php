<?php

namespace Biigle\FileCache\Tests;

use Biigle\FileCache\Contracts\File;
use Biigle\FileCache\Exceptions\FileLockedException;
use Biigle\FileCache\FileCache;
use Biigle\FileCache\GenericFile;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Mockery;

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
        copy(__DIR__.'/files/test-image.jpg', $path);
        $this->assertTrue(touch($path, time() - 1));
        $fileatime = fileatime($path);
        $this->assertNotEquals(time(), $fileatime);
        $file = $cache->get($file, function ($file, $path) {
            return $file;
        });
        $this->assertInstanceof(File::class, $file);
        clearstatcache();
        $this->assertNotEquals($fileatime, fileatime($path));
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
            $this->assertStringContainsString("Disk [abc] does not have a configured driver", $e->getMessage());
        }
    }

    public function testGetDiskLocal()
    {
        $this->app['files']->put("{$this->diskPath}/test-image.jpg", 'abc');
        $file = new GenericFile('test://test-image.jpg');
        $hash = hash('sha256', 'test://test-image.jpg');
        $cache = new FileCache(['path' => $this->cachePath]);

        $path = $cache->get($file, $this->noop);
        $this->assertEquals("{$this->cachePath}/{$hash}", $path);
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

    public function testGetThrowOnLock()
    {
        $cache = new FileCache(['path' => $this->cachePath]);
        $file = new GenericFile('abc://some/image.jpg');
        $hash = hash('sha256', 'abc://some/image.jpg');
        $path = "{$this->cachePath}/{$hash}";
        touch($path, time() - 1);

        $handle = fopen($path, 'w');
        flock($handle, LOCK_EX);

        $this->expectException(FileLockedException::class);
        $cache->get($file, fn ($file, $path) => $file, true);
    }

    public function testGetIgnoreZeroSize()
    {
        $cache = new FileCache(['path' => $this->cachePath]);
        $file = new GenericFile('fixtures://test-file.txt');
        $hash = hash('sha256', 'fixtures://test-file.txt');

        $path = "{$this->cachePath}/{$hash}";
        touch($path);
        $this->assertEquals(0, filesize($path));

        $file = $cache->get($file, function ($file, $path) {
            return $file;
        });

        $this->assertNotEquals(0, filesize($path));
    }

    public function testGetOnce()
    {
        $file = new GenericFile('fixtures://test-image.jpg');
        $hash = hash('sha256', 'fixtures://test-image.jpg');
        $cache = new FileCache(['path' => $this->cachePath]);
        $file = $cache->getOnce($file, function ($file, $path) {
            return $file;
        });
        $this->assertInstanceof(File::class, $file);
        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/{$hash}"));
    }

    public function testGetOnceThrowOnLock()
    {
        $cache = new FileCache(['path' => $this->cachePath]);
        $file = new GenericFile('abc://some/image.jpg');
        $hash = hash('sha256', 'abc://some/image.jpg');
        $path = "{$this->cachePath}/{$hash}";
        touch($path, time() - 1);

        $handle = fopen($path, 'w');
        flock($handle, LOCK_EX);

        $this->expectException(FileLockedException::class);
        $cache->getOnce($file, fn ($file, $path) => $file, true);
    }

    public function testGetStreamCached()
    {
        $file = new GenericFile('test://test-image.jpg');
        $hash = hash('sha256', 'test://test-image.jpg');

        $path = "{$this->cachePath}/{$hash}";
        $oldTime = time() - 1;
        touch($path, $oldTime);

        $cache = new FileCacheStub(['path' => $this->cachePath]);
        $cache->stream = 'abc123';
        $this->assertEquals($oldTime, fileatime($path));
        $this->assertEquals('abc123', $cache->getStream($file));
        clearstatcache();
        $this->assertNotEquals($oldTime, fileatime($path));
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
        $hash = hash('sha256', 'test://test-image.jpg');

        $cache = new FileCache(['path' => $this->cachePath]);
        $paths = $cache->batch([$file, $file2], function ($files, $paths) {
            return $paths;
        });

        $this->assertCount(2, $paths);
        $this->assertStringContainsString($hash, $paths[0]);
        $this->assertStringContainsString($hash, $paths[1]);
    }

    public function testBatchThrowOnLock()
    {
        $cache = new FileCache(['path' => $this->cachePath]);
        $file = new GenericFile('abc://some/image.jpg');
        $hash = hash('sha256', 'abc://some/image.jpg');
        $path = "{$this->cachePath}/{$hash}";
        touch($path, time() - 1);

        $handle = fopen($path, 'w');
        flock($handle, LOCK_EX);

        $this->expectException(FileLockedException::class);
        $cache->batch([$file], fn ($file, $path) => $file, true);
    }

    public function testBatchOnce()
    {
        $file = new GenericFile('fixtures://test-image.jpg');
        $hash = hash('sha256', 'fixtures://test-image.jpg');
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
        $this->app['files']->put("{$this->diskPath}/test-file.txt", 'abc');
        $file = new GenericFile('test://test-file.txt');
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
        $mock = new MockHandler([new Response(404)]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client([
            'handler' => $handlerStack,
            'http_errors' => false,
        ]);

        $file = new GenericFile('https://example.com/file');
        $cache = new FileCache(['path' => $this->cachePath], client: $client);
        $this->assertFalse($cache->exists($file));
    }

    public function testExistsRemote500()
    {
        $mock = new MockHandler([new Response(500)]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client([
            'handler' => $handlerStack,
            'http_errors' => false,
        ]);

        $file = new GenericFile('https://example.com/file');
        $cache = new FileCache(['path' => $this->cachePath], client: $client);
        $this->assertFalse($cache->exists($file));
    }

    public function testExistsRemote200()
    {
        $mock = new MockHandler([new Response(200)]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client([
            'handler' => $handlerStack,
            'http_errors' => false,
        ]);

        $file = new GenericFile('https://example.com/file');
        $cache = new FileCache(['path' => $this->cachePath], client: $client);
        $this->assertTrue($cache->exists($file));
    }

    public function testExistsRemoteTooLarge()
    {
        $mock = new MockHandler([new Response(200, ['content-length' => 100])]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client([
            'handler' => $handlerStack,
            'http_errors' => false,
        ]);

        $file = new GenericFile('https://example.com/file');
        $cache = new FileCache([
            'path' => $this->cachePath,
            'max_file_size' => 50,
        ], client: $client);

        try {
            $cache->exists($file);
            $this->fail('Expected an Exception to be thrown.');
        } catch (Exception $e) {
            $this->assertStringContainsString("too large", $e->getMessage());
        }
    }

    public function testExistsRemoteMimeNotAllowed()
    {
        $mock = new MockHandler([new Response(200, ['content-type' => 'application/json'])]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client([
            'handler' => $handlerStack,
            'http_errors' => false,
        ]);

        $file = new GenericFile('https://example.com/file');
        $cache = new FileCache([
            'path' => $this->cachePath,
            'mime_types' => ['image/jpeg'],
        ], client: $client);

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
