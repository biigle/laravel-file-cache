<?php

namespace Biigle\FileCache\Tests\Facades;

use FileCache;
use Biigle\FileCache\GenericFile;
use Biigle\FileCache\Tests\TestCase;
use Biigle\FileCache\FileCache as BaseFileCache;
use Biigle\FileCache\Facades\FileCache as FileCacheFacade;

class FileCacheTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!class_exists(FileCache::class)) {
            class_alias(FileCacheFacade::class, 'FileCache');
        }
    }

    public function testFacade()
    {
        $this->assertInstanceOf(BaseFileCache::class, FileCache::getFacadeRoot());
    }

    public function testFake()
    {
        FileCache::fake();
        $file = new GenericFile(1, 'https://example.com/image.jpg');
        $path = FileCache::get($file, function ($file, $path) {
            return $path;
        });

        $this->assertFalse($this->app['files']->exists($path));
    }
}
