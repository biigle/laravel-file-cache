<?php

namespace Biigle\FileCache\Testing;

use Biigle\FileCache\Contracts\File;
use Biigle\FileCache\Contracts\FileCache as FileCacheContract;
use Illuminate\Filesystem\Filesystem;

class FileCacheFake implements FileCacheContract
{
    public function __construct()
    {
        (new Filesystem)->cleanDirectory(
            $root = storage_path('framework/testing/disks/file-cache')
        );

        $this->path = $root;
    }

    /**
     * {@inheritdoc}
     */
    public function get(File $file, callable $callback)
    {
        return $this->batch([$file], function ($files, $paths) use ($callback) {
            return call_user_func($callback, $files[0], $paths[0]);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function getOnce(File $file, callable $callback)
    {
        return $this->get($file, $callback);
    }

    /**
     * {@inheritdoc}
     */
    public function getStream(File $file)
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
    public function batch(array $files, callable $callback)
    {
        $paths = array_map(function ($file) {
            $hash = hash('sha256', $file->getUrl());

            return "{$this->path}/{$hash}";
        }, $files);

        return $callback($files, $paths);
    }

    /**
     * {@inheritdoc}
     */
    public function batchOnce(array $files, callable $callback)
    {
        return $this->batch($files, $callback);
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

    /**
     * {@inheritdoc}
     */
    public function exists(File $file)
    {
        return false;
    }
}
