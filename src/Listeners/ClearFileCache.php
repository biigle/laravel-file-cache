<?php

namespace Biigle\FileCache\Listeners;

use Biigle\FileCache\FileCache;

class ClearFileCache
{
    /**
     * Handle the event.
     */
    public function handle()
    {
        app('file-cache')->clear();
    }
}
