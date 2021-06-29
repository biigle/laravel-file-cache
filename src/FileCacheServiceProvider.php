<?php

namespace Biigle\FileCache;

use Biigle\FileCache\Console\Commands\PruneFileCache;
use Biigle\FileCache\Listeners\ClearFileCache;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

class FileCacheServiceProvider extends ServiceProvider
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
            __DIR__.'/config/file-cache.php' => base_path('config/file-cache.php'),
        ], 'config');

        if (method_exists($this->app, 'booted')) {
            // Wait for Laravel to boot before adding the scheduled event.
            // See: https://stackoverflow.com/a/36630136/1796523
            $this->app->booted([$this, 'registerScheduledPruneCommand']);
        } else {
            // Lumen has no 'booted' method but it works without, too, for some reason.
            $this->registerScheduledPruneCommand($this->app);
        }


        $events->listen('cache:clearing', ClearFileCache::class);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/config/file-cache.php', 'file-cache');

        $this->app->bind('file-cache', function () {
            return new FileCache;
        });

        $this->app->singleton('command.file-cache.prune', function ($app) {
            return new PruneFileCache;
        });
        $this->commands('command.file-cache.prune');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [
            'command.file-cache.prune',
        ];
    }

    /**
     * Register the scheduled command to prune the file cache.
     *
     * @param mixed $app Laravel or Lumen application instance.
     */
    public function registerScheduledPruneCommand($app)
    {
        $app->make(Schedule::class)
            ->command(PruneFileCache::class)
            ->cron(config('file-cache.prune_interval'));
    }
}
