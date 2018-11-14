<?php

namespace Biigle\ImageCache\Testing;

use Illuminate\Filesystem\Filesystem;
use Biigle\ImageCache\Contracts\Image;
use Biigle\ImageCache\Contracts\ImageCache as ImageCacheContract;

class ImageCacheFake implements ImageCacheContract
{
    public function __construct()
    {
        (new Filesystem)->cleanDirectory(
            $root = storage_path('framework/testing/disks/image-cache')
        );

        $this->path = $root;
    }

    /**
     * {@inheritdoc}
     */
    public function get(Image $image, callable $callback)
    {
        return $this->batch([$image], function ($images, $paths) use ($callback) {
            return call_user_func($callback, $images[0], $paths[0]);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function getOnce(Image $image, callable $callback)
    {
        return $this->get($image, $callback);
    }

    /**
     * {@inheritdoc}
     */
    public function getStream(Image $image)
    {
        return [
            'stream' => null,
            'size' => 0,
            'mime' => 'inode/x-empty',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function batch(array $images, callable $callback)
    {
        $paths = array_map(function ($image) {
            $hash = hash('sha256', $image->getUrl());

            return "{$this->path}/{$hash}";
        }, $images);

        return $callback($images, $paths);
    }

    /**
     * {@inheritdoc}
     */
    public function batchOnce(array $images, callable $callback)
    {
        return $this->batch($images, $callback);
    }

    /**
     * {@inheritdoc}
     */
    public function prune()
    {
        //
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        //
    }
}
