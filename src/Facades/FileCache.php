<?php

namespace Biigle\FileCache\Facades;

use Biigle\FileCache\Testing\FileCacheFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool exists(\Biigle\FileCache\Contracts\File $file)
 * @method static mixed get(\Biigle\FileCache\Contracts\File $file, callable $callback)
 * @method static mixed getOnce(\Biigle\FileCache\Contracts\File $file, callable $callback)
 * @method static mixed batch(\Biigle\FileCache\Contracts\File[] $files, callable $callback)
 * @method static mixed batchOnce(\Biigle\FileCache\Contracts\File[] $files, callable $callback)
 * @method static void prune()
 * @method static void clear()
 *
 * @see \Biigle\FileCache\FileCache;
 */
class FileCache extends Facade
{
    /**
     * Use testing instance.
     */
    public static function fake(): void
    {
        static::swap(new FileCacheFake(static::getFacadeApplication()));
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'file-cache';
    }
}
