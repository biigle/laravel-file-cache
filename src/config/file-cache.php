<?php

return [

    /*
    | Maximum allowed size of a cached file in bytes. Set to -1 to allow any size.
    */
    'max_file_size' => env('FILE_CACHE_MAX_FILE_SIZE', -1),

    /*
    | Maximum age in minutes of an file in the cache. Older files are pruned.
    */
    'max_age' => env('FILE_CACHE_MAX_AGE', 60),

    /*
    | Maximum size (soft limit) of the file cache in bytes. If the cache exceeds
    | this size, old files are pruned.
    */
    'max_size' => env('FILE_CACHE_MAX_SIZE', 1E+9), // 1 GB

    /*
    | Directory to use for the file cache.
    */
    'path' => storage_path('framework/cache/files'),

    /*
     | Read timeout in seconds for fetching remote files. If the stream transmits
     | no data for longer than this period (or cannot be established), caching the
     | file fails.
     */
    'timeout' => env('FILE_CACHE_TIMEOUT', 5.0),

    /*
     | Interval for the scheduled task to prune the file cache.
     */
    'prune_interval' => '*/5 * * * *', // Every five minutes

    /*
     | Allowed MIME types for cached files. Fetching of files with any other type fails.
     | This is especially useful for files from a remote source. Leave empty to allow all
     | types.
     */
    'mime_types' => [],

];
