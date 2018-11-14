<?php

namespace Biigle\ImageCache\Contracts;

interface ImageCache
{
    /**
     * Perform a callback with the path of a cached image. This takes care of shared
     * locks on the cached image file so it is not corrupted due to concurrent write
     * operations.
     *
     * @param Image $image
     * @param callable $callback Gets the image object and the path to the cached image
     * file as arguments.
     *
     * @return mixed Result of the callback.
     */
    public function get(Image $image, callable $callback);

    /**
     * Like `get` but deletes the cached image afterwards (if it is not used somewhere
     * else).
     *
     * @param Image $image
     * @param callable $callback Gets the image object and the path to the cached image
     * file as arguments.
     *
     * @return mixed Result of the callback.
     */
    public function getOnce(Image $image, callable $callback);

    /**
     * Get a stream resource for an image. If the image is cached, the resource points
     * to the cached file instead. This will not cache uncached images. Make sure to
     * close the streams!
     *
     * @param Image $image
     * @throws Exception If the storage disk does not exist or the file was not found.
     *
     * @return resource
     */
    public function getStream(Image $image);

    /**
     * Perform a callback with the paths of many cached images. Use this to prevent
     * pruning of the images while they are processed.
     *
     * @param array $images
     * @param callable $callback Gets the array of image objects and the array of paths
     * to the cached image files (in the same ordering) as arguments.
     *
     * @return mixed Result of the callback.
     */
    public function batch(array $images, callable $callback);

    /**
     * Like `batch` but deletes the cached images afterwards (if they are not used
     * somewhere else).
     *
     * @param array $images
     * @param callable $callback Gets the array of image objects and the array of paths
     * to the cached image files (in the same ordering) as arguments.
     *
     * @return mixed Result of the callback.
     */
    public function batchOnce(array $images, callable $callback);

    /**
     * Remove cached images that are too old or exceed the maximum cache size.
     */
    public function prune();

    /**
     * Delete all unused cached images.
     */
    public function clear();
}
