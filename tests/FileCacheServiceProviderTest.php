<?php

namespace Biigle\FileCache\Tests;

use Biigle\FileCache\FileCache;
use Illuminate\Support\Facades\Event;
use Illuminate\Console\Scheduling\Schedule;

class FileCacheServiceProviderTest extends TestCase
{
    public function testScheduledCommand()
    {
        $schedule = $this->app[Schedule::class];
        $event = $schedule->events()[0];
        $this->assertContains('prune-file-cache', $event->command);
    }
}
