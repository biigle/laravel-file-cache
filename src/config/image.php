<?php

return [

    /*
    | Settings for the image cache. The image cache caches remote or cloud storage
    | images locally so they don't have to be downloaded too often.
    */
    'cache' => [

        /*
        | Maximum allowed size of a cached image in bytes. Set to -1 to allow any size.
        */
        'max_image_size' => env('IMAGE_CACHE_MAX_IMAGE_SIZE', 1E+8), // 100 MB

        /*
        | Maximum age in minutes of an image in the cache. Older images are pruned.
        */
        'max_age' => env('IMAGE_CACHE_MAX_AGE', 60),

        /*
        | Maximum size (soft limit) of the image cache in bytes. If the cache exceeds
        | this size, old images are pruned.
        */
        'max_size' => env('IMAGE_CACHE_MAX_SIZE', 1E+9), // 1 GB

        /*
        | Directory to use for the image cache.
        */
        'path' => storage_path('framework/cache/images'),

        /*
         | Read timeout in seconds for fetching remote images. If the stream transmits
         | no data for longer than this period (or cannot be established), caching the
         | image fails.
         */
        'timeout' => env('IMAGE_CACHE_TIMEOUT', 5.0),

    ],

];
