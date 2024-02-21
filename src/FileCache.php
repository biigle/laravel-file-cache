<?php

namespace Biigle\FileCache;

use Biigle\FileCache\Contracts\File;
use Biigle\FileCache\Contracts\FileCache as FileCacheContract;
use Biigle\FileCache\Exceptions\FileLockedException;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * The file cache.
 */
class FileCache implements FileCacheContract
{

    /**
     * File cache configuration.
     *
     * @var array
     */
    protected $config;

    /**
     * The Filesytem instance to use
     *
     * @var Filesystem
     */
    protected $files;

    /**
     * File FilesystemManager instance to use
     *
     * @var FilesystemManager
     */
    protected $storage;

    /**
     * Guzzle HTTP client to use
     *
     * @var ClientInterface
     */
    protected $client;

    /**
     * Create an instance.
     *
     * @param array $config Optional custom configuration.
     * @param Filesystem $files
     * @param FilesystemManager $storage
     */
    public function __construct(array $config = [], $files = null, $storage = null, $client = null)
    {
        $this->config = array_merge(config('file-cache'), $config);
        $this->files = $files ?: app('files');
        $this->storage = $storage ?: app('filesystem');
        $this->client = $client ?: $this->makeHttpClient();
    }

    /**
     * {@inheritdoc}
     */
    public function exists(File $file)
    {
        if ($this->isRemote($file)) {
            return $this->existsRemote($file);
        }

        return $this->existsDisk($file);
    }

    /**
     * {@inheritdoc}
     */
    public function get(File $file, callable $callback, bool $throwOnLock = false)
    {
        return $this->batch([$file], function ($files, $paths) use ($callback) {
            return call_user_func($callback, $files[0], $paths[0]);
        }, $throwOnLock);
    }

    /**
     * {@inheritdoc}
     */
    public function getOnce(File $file, callable $callback, bool $throwOnLock = false)
    {
        return $this->batchOnce([$file], function ($files, $paths) use ($callback) {
            return call_user_func($callback, $files[0], $paths[0]);
        }, $throwOnLock);
    }

    /**
     * {@inheritdoc}
     */
    public function getStream(File $file)
    {
        $cachedPath = $this->getCachedPath($file);

        if ($this->files->exists($cachedPath)) {
            // Update access and modification time to signal that this cached file was
            // used recently.
            touch($cachedPath);

            return $this->getFileStream($cachedPath);
        }

        if ($this->isRemote($file)) {
            return $this->getFileStream($cachedPath);
        }

        $url = explode('://', $file->getUrl());

        // Throws an exception if the disk does not exist.
        $disk = $this->storage->disk($url[0]);
        $stream = $disk->readStream($url[1]);

        if (is_null($stream)) {
            throw new Exception('File does not exist.');
        }

        return $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function batch(array $files, callable $callback, bool $throwOnLock = false)
    {
        $retrieved = array_map(function ($file) use ($throwOnLock) {
            return $this->retrieve($file, $throwOnLock);
        }, $files);

        $paths = array_map(function ($file) {
            return $file['path'];
        }, $retrieved);

        try {
            $result = call_user_func($callback, $files, $paths);
        } finally {
            foreach ($retrieved as $file) {
                fclose($file['handle']);
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function batchOnce(array $files, callable $callback, bool $throwOnLock = false)
    {
        $retrieved = array_map(function ($file) use ($throwOnLock) {
            return $this->retrieve($file, $throwOnLock);
        }, $files);

        $paths = array_map(function ($file) {
            return $file['path'];
        }, $retrieved);

        try {
            $result = call_user_func($callback, $files, $paths);
        } finally {
            foreach ($retrieved as $index => $file) {
                // Convert to exclusive lock for deletion. Don't delete if lock can't be
                // obtained.
                if (flock($file['handle'], LOCK_EX | LOCK_NB)) {
                    // This path is not the same than $file['path'] for locally stored
                    // files. We don't want to delete locally stored files.
                    $path = $this->getCachedPath($files[$index]);
                    if ($this->files->exists($path)) {
                        $this->files->delete($path);
                    }
                }
                fclose($file['handle']);
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function prune()
    {
        if (!$this->files->exists($this->config['path'])) {
            return;
        }

        $now = time();
        // Allowed age in seconds.
        $allowedAge = $this->config['max_age'] * 60;
        $totalSize = 0;

        $files = Finder::create()
            ->files()
            ->ignoreDotFiles(true)
            ->in($this->config['path'])
            ->getIterator();

        // Prune files by age.
        foreach ($files as $file) {
            try {
                $aTime = $file->getATime();
            } catch (RuntimeException $e) {
                // This can happen if the file is deleted in the meantime.
                continue;
            }

            if (($now - $aTime) > $allowedAge && $this->delete($file)) {
                continue;
            }

            $totalSize += $file->getSize();
        }

        $allowedSize = $this->config['max_size'];

        // Prune files by cache size.
        if ($totalSize > $allowedSize) {
            $files = Finder::create()
                ->files()
                ->ignoreDotFiles(true)
                ->in($this->config['path'])
                ->getIterator();

            $files = iterator_to_array($files);
            // This will return the least recently accessed files first.
            // We use a custom sorting function which ignores errors (because files may
            // have been deleted in the meantime).
            uasort($files, function (SplFileInfo $a, SplFileInfo $b) {
                try {
                    $aTime = $a->getATime();
                } catch (RuntimeException $e) {
                    return 1;
                }

                try {
                    $bTime = $b->getATime();
                } catch (RuntimeException $e) {
                    return -1;
                }

                return $aTime - $bTime;
            });

            foreach ($files as $file) {
                if ($totalSize <= $allowedSize) {
                    break;
                }

                $fileSize = $file->getSize();
                if ($this->delete($file)) {
                    $totalSize -= $fileSize;
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        if (!$this->files->exists($this->config['path'])) {
            return;
        }

        $files = Finder::create()
            ->files()
            ->ignoreDotFiles(true)
            ->in($this->config['path'])
            ->getIterator();

        foreach ($files as $file) {
            $this->delete($file);
        }
    }

    /**
     * Check for existence of a remte file.
     *
     * @param File $file
     *
     * @return bool
     */
    protected function existsRemote($file)
    {
        $response = $this->client->head($file->getUrl());
        $code = $response->getStatusCode();

        if ($code < 200 || $code >= 300) {
            return false;
        }

        if (!empty($this->config['mime_types'])) {
            $type = $response->getHeaderLine('content-type');
            $type = trim(explode(';', $type)[0]);
            if (!in_array($type, $this->config['mime_types'])) {
                throw new Exception("MIME type '{$type}' not allowed.");
            }
        }

        $maxBytes = intval($this->config['max_file_size']);
        $size = intval($response->getHeaderLine('content-length'));

        if ($maxBytes >= 0 && $size > $maxBytes) {
            throw new Exception("The file is too large with more than {$maxBytes} bytes.");
        }

        return true;
    }

    /**
     * Check for existence of a file from a storage disk.
     *
     * @param File $file
     *
     * @return bool
     */
    protected function existsDisk($file)
    {
        $url = explode('://', $file->getUrl());
        $exists = $this->getDisk($file)->exists($url[1]);

        if (!$exists) {
            return false;
        }

        if (!empty($this->config['mime_types'])) {
            $type = $this->getDisk($file)->mimeType($url[1]);
            if (!in_array($type, $this->config['mime_types'])) {
                throw new Exception("MIME type '{$type}' not allowed.");
            }
        }

        $maxBytes = intval($this->config['max_file_size']);

        if ($maxBytes >= 0) {
            $size = $this->getDisk($file)->size($url[1]);
            if ($size > $maxBytes) {
                throw new Exception("The file is too large with more than {$maxBytes} bytes.");
            }
        }

        return true;
    }

    /**
     * Delete a cached file it it is not used.
     *
     * @param SplFileInfo $file
     *
     * @return bool If the file has been deleted.
     */
    protected function delete(SplFileInfo $file)
    {
        $handle = fopen($file->getRealPath(), 'r');
        $deleted = false;

        try {
            // Only delete the file if it is not currently used. Else move on.
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->files->delete($file->getRealPath());
                $deleted = true;
            }
        } finally {
            fclose($handle);
        }

        return $deleted;
    }

    /**
     * Cache a remote or cloud storage file if it is not cached and get the path to
     * the cached file. If the file is local, nothing will be done and the path to the
     * local file will be returned.
     *
     * @param File $file File to get the path for
     * @param bool $throwOnLock Whether to throw an exception if a file is currently locked (i.e. written to). Otherwise the method will wait until the lock is released.
     * @throws Exception If the file could not be cached.
     * @throws FileLockedException If the file is locked and `throwOnLock` was `true`.
     *
     *
     * @return array Containing the 'path' to the file and the file 'handle'. Close the
     * handle when finished.
     */
    protected function retrieve(File $file, bool $throwOnLock = false)
    {
        $this->ensurePathExists();
        $cachedPath = $this->getCachedPath($file);

        // This will return false if the file already exists. Else it will create it in
        // read and write mode.
        $handle = @fopen($cachedPath, 'x+');

        if ($handle === false) {
            // The file exists, get the file handle in read mode.
            $handle = fopen($cachedPath, 'r');
            if ($throwOnLock && !flock($handle, LOCK_SH | LOCK_NB)) {
                throw new FileLockedException;
            }

            // Wait for any LOCK_EX that is set if the file is currently written.
            flock($handle, LOCK_SH);

            $stat = fstat($handle);
            // Check if the file is still there since the writing operation could have
            // failed. If the file is gone, retry retrieve.
            if ($stat['nlink'] === 0) {
                fclose($handle);
                return $this->retrieve($file);
            }

            // File caching may have failed and left an empty file in the cache.
            // Delete the empty file and try to cache the file again.
            if ($stat['size'] === 0) {
                fclose($handle);
                $this->delete(new SplFileInfo($cachedPath));
                return $this->retrieve($file);
            }

            // The file exists and is no longer written to.
            return $this->retrieveExistingFile($cachedPath, $handle);
        }

        // The file did not exist and should be written. Hold LOCK_EX until writing
        // finished.
        flock($handle, LOCK_EX);

        try {
            $fileInfo = $this->retrieveNewFile($file, $cachedPath, $handle);
            // Convert the lock so other workers can use the file from now on.
            flock($handle, LOCK_SH);
        } catch (Exception $e) {
            // Remove the empty file if writing failed. This is the case that is caught
            // by 'nlink' === 0 above.
            @unlink($cachedPath);
            fclose($handle);
            throw new Exception("Error while caching file '{$file->getUrl()}': {$e->getMessage()}");
        }

        return $fileInfo;
    }

    /**
     * Get path and handle for a file that exists in the cache.
     *
     * @param string $cachedPath
     * @param resource $handle
     *
     * @return array
     */
    protected function retrieveExistingFile($cachedPath, $handle)
    {
        // Update access and modification time to signal that this cached file was
        // used recently.
        touch($cachedPath);

        return [
            'path' => $cachedPath,
            'handle' => $handle,
        ];
    }

    /**
     * Get path and handle for a file that does not yet exist in the cache.
     *
     * @param File $file
     * @param string $cachedPath
     * @param resource $handle
     *
     * @return array
     */
    protected function retrieveNewFile(File $file, $cachedPath, $handle)
    {
        if ($this->isRemote($file)) {
            $source = $this->getFileStream($file->getUrl());
            $cachedPath = $this->cacheFromResource($file, $source, $handle);
            if (is_resource($source)) {
                fclose($source);
            }
        } else {
            $newCachedPath = $this->getDiskFile($file, $handle);

            // If it is a locally stored file, delete the empty "placeholder"
            // file again. The handle may stay open; it doesn't matter.
            if ($newCachedPath !== $cachedPath) {
                unlink($cachedPath);
            }

            $cachedPath = $newCachedPath;
        }

        if (!empty($this->config['mime_types'])) {
            $type = $this->files->mimeType($cachedPath);
            if (!in_array($type, $this->config['mime_types'])) {
                throw new Exception("MIME type '{$type}' not allowed.");
            }
        }

        return [
            'path' => $cachedPath,
            'handle' => $handle,
        ];
    }

    /**
     * Cache an file from a storage disk and get the path to the cached file. Files
     * from local disks are not cached.
     *
     * @param File $file Cloud storage file
     * @param resource $target Target file resource
     * @throws Exception If the file could not be cached.
     *
     * @return string
     */
    protected function getDiskFile(File $file, $target)
    {
        $url = explode('://', $file->getUrl());
        $disk = $this->getDisk($file);

        $source = $disk->readStream($url[1]);
        if (is_null($source)) {
            throw new Exception('File does not exist.');
        }

        $cachedPath = $this->cacheFromResource($file, $source, $target);
        if (is_resource($source)) {
            fclose($source);
        }

        return $cachedPath;
    }

    /**
     * Store the file from the given resource to a cached file.
     *
     * @param File $file
     * @param resource $source
     * @param resource $target
     * @throws Exception If the file could not be cached.
     *
     * @return string Path to the cached file
     */
    protected function cacheFromResource(File $file, $source, $target)
    {
        if (!is_resource($source)) {
            throw new Exception('The source resource could not be established.');
        }

        $cachedPath = $this->getCachedPath($file);
        $maxBytes = intval($this->config['max_file_size']);
        $bytes = stream_copy_to_stream($source, $target, $maxBytes);

        if ($bytes === $maxBytes) {
            throw new Exception("The file is too large with more than {$maxBytes} bytes.");
        }

        if ($bytes === false) {
            throw new Exception('The source resource is invalid.');
        }

        $metadata = stream_get_meta_data($source);

        if (array_key_exists('timed_out', $metadata) && $metadata['timed_out']) {
            throw new Exception('The source stream timed out while reading data.');
        }

        return $cachedPath;
    }

    /**
     * Creates the cache directory if it doesn't exist yet.
     */
    protected function ensurePathExists()
    {
        if (!$this->files->exists($this->config['path'])) {
            $this->files->makeDirectory($this->config['path'], 0755, true, true);
        }
    }

    /**
     * Get the path to the cached file file.
     *
     * @param File $file
     *
     * @return string
     */
    protected function getCachedPath(File $file)
    {
        $hash = hash('sha256', $file->getUrl());

        return "{$this->config['path']}/{$hash}";
    }

    /**
     * Get the stream resource for an file.
     *
     * @param string $url
     *
     * @return resource
     */
    protected function getFileStream($url)
    {
        if (strpos($url, 'http') === 0) {
            return $this->client->get($url)->getBody()->detach();
        }

        return @fopen($url, 'r');
    }

    /**
     * Determine if an file is remote, i.e. served by a public webserver.
     *
     * @param File $file
     *
     * @return boolean
     */
    protected function isRemote(File $file)
    {
        return strpos($file->getUrl(), 'http') === 0;
    }

    /**
     * Get the storage disk on which a file is stored.
     *
     * @param File $file
     *
     * @return \Illuminate\Contracts\Filesystem\Filesystem
     */
    protected function getDisk(File $file)
    {
        $url = explode('://', $file->getUrl());

        // Throws an exception if the disk does not exist.
        return $this->storage->disk($url[0]);
    }

    /**
     * Create a new Guzzle HTTP client.
     *
     * @return ClientInterface
     */
    protected function makeHttpClient(): ClientInterface
    {
        return new Client([
            'timeout' => $this->config['timeout'],
            'connect_timeout' => $this->config['connect_timeout'],
            'read_timeout' => $this->config['read_timeout'],
            'http_errors' => false,
        ]);
    }
}
