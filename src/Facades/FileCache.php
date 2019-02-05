<?php

namespace Biigle\FileCache\Facades;

use Illuminate\Support\Facades\Facade;
use Biigle\FileCache\Testing\FileCacheFake;

class FileCache extends Facade
{
    /**
     * Use testing instance.
     *
     * @return void
     */
    public static function fake()
    {
        static::swap(new FileCacheFake(static::getFacadeApplication()));
    }

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'file-cache';
    }
}
