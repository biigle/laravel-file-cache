<?php

namespace Biigle\ImageCache\Console\Commands;

use Illuminate\Console\Command;
use Biigle\ImageCache\ImageCache;

class PruneImageCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prune-image-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove cached images that are too old or exceed the maximum cache size';

    /**
     * Execute the console command.
     *
     * @param ImageCache $cache
     *
     * @return mixed
     */
    public function handle(ImageCache $cache)
    {
        $cache->prune();
    }
}
