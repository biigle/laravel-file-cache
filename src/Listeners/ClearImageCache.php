<?php

namespace Biigle\ImageCache\Listeners;

use Biigle\ImageCache\ImageCache;

class ClearImageCache
{
    /**
     * Handle the event.
     *
     * @param ImageCache $cache
     */
    public function handle()
    {
        app('image-cache')->clear();
    }
}
