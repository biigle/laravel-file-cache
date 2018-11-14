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
    public function get(Image $image, $callback)
    {
        $hash = hash('sha256', $image->getUrl());

        return $callback($image, "{$this->path}/{$hash}");
    }

    /**
     * {@inheritdoc}
     */
    public function getOnce(Image $image, $callback)
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
