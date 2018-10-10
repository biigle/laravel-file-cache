<?php

namespace Biigle\ImageCache;

use Illuminate\Support\ServiceProvider;

class ImageCacheServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/config/image.php' => config_path('image.php'),
        ], 'config');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/config/image.php', 'image');
    }
}
