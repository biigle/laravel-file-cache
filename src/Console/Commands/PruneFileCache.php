<?php

namespace Biigle\FileCache\Console\Commands;

use Biigle\FileCache\FileCache;
use Illuminate\Console\Command;

class PruneFileCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prune-file-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove cached files that are too old or exceed the maximum cache size';

    /**
     * Execute the console command.
     *
     * @param FileCache $cache
     *
     * @return mixed
     */
    public function handle(FileCache $cache)
    {
        $cache->prune();
    }
}
