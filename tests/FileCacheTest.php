<?php

namespace Biigle\FileCache\Tests;

use Biigle\FileCache\Contracts\File;
use Biigle\FileCache\Exceptions\FileIsTooLargeException;
use Biigle\FileCache\Exceptions\MimeTypeIsNotAllowedException;
use Biigle\FileCache\FileCache;
use Biigle\FileCache\GenericFile;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;

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
        $mock = new MockHandler([
            new Response(200, [], file_get_contents(__DIR__.'/files/test-image.jpg')),
        ]);
        $cache = new FileCache(['path' => $this->cachePath], new Client(['handler' => HandlerStack::create($mock)]));

        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/{$hash}"));
        $path = $cache->get($file, $this->noop);
        $this->assertEquals("{$this->cachePath}/{$hash}", $path);
        $this->assertTrue($this->app['files']->exists("{$this->cachePath}/{$hash}"));
    }

    public function testGetRemoteTooLarge()
    {
        $file = new GenericFile('https://files/image.jpg');
        $hash = hash('sha256', 'https://files/image.jpg');
        $mock = new MockHandler([
            new Response(200, [], file_get_contents(__DIR__.'/files/test-image.jpg')),
        ]);
        $cache = new FileCache([
            'path' => $this->cachePath,
            'max_file_size' => 1,
        ], new Client(['handler' => HandlerStack::create($mock)]));

        $this->expectException(FileIsTooLargeException::class);
        $cache->get($file, $this->noop);
        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/{$hash}"));
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

        $this->expectException(FileNotFoundException::class);
        $cache->get($file, $this->noop);
    }

    public function testGetDiskCloud()
    {
        config(['filesystems.disks.s3' => ['driver' => 's3']]);
        $file = new GenericFile('s3://files/test-image.jpg');
        $hash = hash('sha256', 's3://files/test-image.jpg');

        $stream = fopen(__DIR__.'/files/test-image.jpg', 'rb');
        $filesystemManagerMock = $this->createMock(FilesystemManager::class);
        $filesystemMock = $this->createMock(FilesystemAdapter::class);
        $filesystemMock->method('readStream')->willReturn($stream);
        $filesystemMock->method('getDriver')->willReturn($filesystemMock);
        $filesystemMock->method('get')->willReturn($filesystemMock);
        $filesystemManagerMock->method('disk')->with('s3')->willReturn($filesystemMock);
        $this->app['filesystem'] = $filesystemManagerMock;

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

        $stream = fopen(__DIR__.'/files/test-image.jpg', 'rb');

        $filesystemManagerMock = $this->createMock(FilesystemManager::class);
        $filesystemMock = $this->createMock(FilesystemAdapter::class);
        $filesystemMock->method('readStream')->willReturn($stream);
        $filesystemMock->method('getDriver')->willReturn($filesystemMock);
        $filesystemMock->method('get')->willReturn($filesystemMock);
        $filesystemManagerMock->method('disk')->with('s3')->willReturn($filesystemMock);
        $this->app['filesystem'] = $filesystemManagerMock;

        $cache = new FileCache([
            'path' => $this->cachePath,
            'max_file_size' => 1,
        ]);

        $this->assertFalse($this->app['files']->exists("{$this->cachePath}/{$hash}"));
        $this->expectException(FileIsTooLargeException::class);
        $cache->get($file, $this->noop);
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
        $handle = fopen("{$this->cachePath}/def", 'rb');
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
        } catch (MimeTypeIsNotAllowedException $e) {
            $this->assertStringContainsString("text/plain", $e->getMessage());
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

        $this->expectException(FileIsTooLargeException::class);
        $cache->exists($file);
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
        } catch (MimeTypeIsNotAllowedException $e) {
            $this->assertStringContainsString("text/plain", $e->getMessage());
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

        $this->expectException(FileIsTooLargeException::class);
        $cache->exists($file);
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
        } catch (MimeTypeIsNotAllowedException $e) {
            $this->assertStringContainsString("application/json", $e->getMessage());
        }
    }
}
