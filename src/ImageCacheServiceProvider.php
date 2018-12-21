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
     * @param Dispatcher $events
     *
     * @return void
     */
    public function boot(Dispatcher $events)
    {
        $this->publishes([
            __DIR__.'/config/image.php' => base_path('config/image.php'),
        ], 'config');

        // Wait for the application to boot before adding the scheduled event.
        // See: https://stackoverflow.com/a/36630136/1796523
        $this->app->booted(function ($app) {
            $app->make(Schedule::class)
                ->command(PruneImageCache::class)
                ->cron(config('image.cache.prune_interval'));
        });

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

        $this->app->singleton('command.image-cache.prune', function ($app) {
            return new PruneImageCache;
        });
        $this->commands('command.image-cache.prune');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [
            'command.image-cache.prune',
        ];
    }
}
