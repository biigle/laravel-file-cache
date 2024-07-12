<?php

namespace Biigle\FileCache\Tests;

use Biigle\FileCache\FileCache;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

class FileCacheServiceProviderTest extends TestCase
{
    public function testScheduledCommand()
    {
        config(['file-cache.prune_interval' => '*/5 * * * *']);
        $schedule = $this->app[Schedule::class];
        $event = $schedule->events()[1];
        $this->assertStringContainsString('prune-file-cache', $event->command);
        $this->assertEquals('*/5 * * * *', $event->expression);
    }
}
