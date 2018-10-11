<?php

namespace Biigle\ImageCache;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Console\Scheduling\Schedule;
use Biigle\ImageCache\Listeners\ClearImageCache;
use Biigle\ImageCache\Console\Commands\PruneImageCache;

class ImageCacheServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     * @param Schedule $schedule
     * @param Dispatcher $events
     *
     * @return void
     */
    public function boot(Schedule $schedule, Dispatcher $events)
    {
        $this->publishes([
            __DIR__.'/config/image.php' => config_path('image.php'),
        ], 'config');

        $schedule->command(PruneImageCache::class)
            ->cron(config('image.cache.prune_interval'));

        $events->listen('cache:clearing', ClearImageCache::class);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/config/image.php', 'image');

        $this->app->bind('image-cache', function () {
            return new ImageCache;
        });
    }
}
