<?php

namespace Biigle\FileCache\Listeners;

use Biigle\FileCache\FileCache;

class ClearFileCache
{
    /**
     * Handle the event.
     *
     * @param FileCache $cache
     */
    public function handle()
    {
        app('file-cache')->clear();
    }
}
