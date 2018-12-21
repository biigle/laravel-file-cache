<?php

namespace Biigle\ImageCache\Tests;

use Biigle\ImageCache\ImageCache;
use Illuminate\Support\Facades\Event;
use Illuminate\Console\Scheduling\Schedule;

class ImageCacheServiceProviderTest extends TestCase
{
    public function testScheduledCommand()
    {
        $schedule = $this->app[Schedule::class];
        $event = $schedule->events()[0];
        $this->assertContains('prune-image-cache', $event->command);
    }
}
