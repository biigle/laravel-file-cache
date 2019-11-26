<?php

namespace Biigle\FileCache\Contracts;

interface FileCache
{
    /**
     * Perform a callback with the path of a cached file. This takes care of shared
     * locks on the cached file file so it is not corrupted due to concurrent write
     * operations.
     *
     * @param File $file
     * @param callable $callback Gets the file object and the path to the cached file
     * file as arguments.
     *
     * @return mixed Result of the callback.
     */
    public function get(File $file, callable $callback);

    /**
     * Like `get` but deletes the cached file afterwards (if it is not used somewhere
     * else).
     *
     * @param File $file
     * @param callable $callback Gets the file object and the path to the cached file
     * file as arguments.
     *
     * @return mixed Result of the callback.
     */
    public function getOnce(File $file, callable $callback);

    /**
     * Get a stream resource for an file. If the file is cached, the resource points
     * to the cached file instead. This will not cache uncached files. Make sure to
     * close the streams!
     *
     * @param File $file
     * @throws Exception If the storage disk does not exist or the file was not found.
     *
     * @return resource
     */
    public function getStream(File $file);

    /**
     * Perform a callback with the paths of many cached files. Use this to prevent
     * pruning of the files while they are processed.
     *
     * @param array $files
     * @param callable $callback Gets the array of file objects and the array of paths
     * to the cached file files (in the same ordering) as arguments.
     *
     * @return mixed Result of the callback.
     */
    public function batch(array $files, callable $callback);

    /**
     * Like `batch` but deletes the cached files afterwards (if they are not used
     * somewhere else).
     *
     * @param array $files
     * @param callable $callback Gets the array of file objects and the array of paths
     * to the cached file files (in the same ordering) as arguments.
     *
     * @return mixed Result of the callback.
     */
    public function batchOnce(array $files, callable $callback);

    /**
     * Remove cached files that are too old or exceed the maximum cache size.
     */
    public function prune();

    /**
     * Delete all unused cached files.
     */
    public function clear();

    /**
     * Check if a file exists.
     *
     * @param File $file
     *
     * @return bool Whether the file exists or not.
     */
    public function exists(File $file);
}
