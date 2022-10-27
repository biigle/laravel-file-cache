<?php

namespace Biigle\FileCache\Testing;

use Biigle\FileCache\Contracts\File;
use Biigle\FileCache\Contracts\FileCache as FileCacheContract;
use Illuminate\Filesystem\Filesystem;

class FileCacheFake implements FileCacheContract
{
    protected string $path;

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
            return $callback($files[0], $paths[0]);
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
    public function getStream(File $file): array
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
    public function prune(): void
    {
        //
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        //
    }

    /**
     * {@inheritdoc}
     */
    public function exists(File $file): bool
    {
        return false;
    }
}
