<?php

namespace Biigle\ImageCache\Facades;

use Illuminate\Support\Facades\Facade;
use Biigle\ImageCache\Testing\ImageCacheFake;

class ImageCache extends Facade
{
    /**
     * Use testing instance.
     *
     * @return void
     */
    public static function fake()
    {
        static::swap(new ImageCacheFake(static::getFacadeApplication()));
    }

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'image-cache';
    }
}
