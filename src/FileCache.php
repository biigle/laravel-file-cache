<?php

namespace Biigle\FileCache;

use Biigle\FileCache\Contracts\File;
use Biigle\FileCache\Contracts\FileCache as FileCacheContract;
use Biigle\FileCache\Exceptions\FileIsTooLargeException;
use Biigle\FileCache\Exceptions\MimeTypeIsNotAllowedException;
use Biigle\FileCache\Exceptions\SourceResourceIsInvalidException;
use Biigle\FileCache\Exceptions\SourceResourceTimedOutException;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * The file cache.
 */
class FileCache implements FileCacheContract
{
    /**
     * Create an instance.
     */
    public function __construct(
        protected array $config = [],
        protected ?Client $client = null,
        protected ?Filesystem $files = null,
        protected ?FilesystemManager $storage = null
    )
    {
        $this->config = $this->prepareConfig(array_merge(config('file-cache'), $config));
        $this->client = $client ?: new Client();
        $this->files = $files ?: app('files');
        $this->storage = $storage ?: app('filesystem');
    }

    /**
     * {@inheritdoc}
     *
     * @throws MimeTypeIsNotAllowedException
     * @throws FileIsTooLargeException
     */
    public function exists(File $file): bool
    {
        if ($this->isRemote($file)) {
            return $this->existsRemote($file);
        }

        return $this->existsDisk($file);
    }

    /**
     * {@inheritdoc}
     *
     * @throws GuzzleException
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     * @throws MimeTypeIsNotAllowedException
     */
    public function get(File $file, ?callable $callback = null)
    {
        $callback = $callback ?? \Closure::fromCallable([static::class, 'defaultGetCallback']);

        return $this->batch([$file], function ($files, $paths) use ($callback) {
            return $callback($files[0], $paths[0]);
        });
    }

    /**
     * {@inheritdoc}
     *
     * @throws GuzzleException
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     * @throws MimeTypeIsNotAllowedException
     */
    public function getOnce(File $file, ?callable $callback = null)
    {
        $callback = $callback ?? \Closure::fromCallable([static::class, 'defaultGetCallback']);

        return $this->batchOnce([$file], function ($files, $paths) use ($callback) {
            return $callback($files[0], $paths[0]);
        });
    }

    /**
     * {@inheritdoc}
     *
     * @throws GuzzleException
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     * @throws MimeTypeIsNotAllowedException
     */
    public function batch(array $files, ?callable $callback = null)
    {
        $callback = $callback ?? \Closure::fromCallable([static::class, 'defaultBatchCallback']);

        $retrieved = array_map(function ($file) {
            return $this->retrieve($file);
        }, $files);

        $paths = array_map(static function ($file) {
            return $file['path'];
        }, $retrieved);

        try {
            $result = $callback($files, $paths);
        } finally {
            foreach ($retrieved as $file) {
                fclose($file['stream']);
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     *
     * @throws GuzzleException
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     * @throws MimeTypeIsNotAllowedException
     */
    public function batchOnce(array $files, ?callable $callback = null)
    {
        $callback = $callback ?? \Closure::fromCallable([static::class, 'defaultBatchCallback']);

        $retrieved = array_map(function ($file) {
            return $this->retrieve($file);
        }, $files);

        $paths = array_map(static function ($file) {
            return $file['path'];
        }, $retrieved);

        try {
            $result = $callback($files, $paths);
        } finally {
            foreach ($retrieved as $index => $file) {
                // Convert to exclusive lock for deletion. Don't delete if lock can't be
                // obtained.
                if (flock($file['stream'], LOCK_EX | LOCK_NB)) {
                    // This path is not the same than $file['path'] for locally stored
                    // files. We don't want to delete locally stored files.
                    $path = $this->getCachedPath($files[$index]);
                    if ($this->files->exists($path)) {
                        $this->files->delete($path);
                    }
                }
                fclose($file['stream']);
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function prune(): void
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
            if ($now - $file->getATime() > $allowedAge && $this->delete($file)) {
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
                // This will return the least recently accessed files first.
                ->sortByAccessedTime()
                ->in($this->config['path'])
                ->getIterator();

            while ($totalSize > $allowedSize && ($file = $files->current())) {
                $fileSize = $file->getSize();
                if ($this->delete($file)) {
                    $totalSize -= $fileSize;
                }
                $files->next();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
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
     * Check for existence of a remote file.
     *
     * @throws MimeTypeIsNotAllowedException
     * @throws FileIsTooLargeException
     */
    protected function existsRemote(File $file): bool
    {
        try {
            $headers = $this->client->head($this->encodeUrl($file->getUrl()), [
                'timeout' => $this->config['timeout']
            ])->getHeaders();
        } catch (GuzzleException) {
            return false;
        }

        if (!empty($this->config['mime_types'])) {
            $contentType = $headers['Content-Type'][array_key_last($headers['Content-Type'])] ?? '';
            $type = trim(explode(';', $contentType)[0]);
            if ($type && !in_array($type, $this->config['mime_types'], true)) {
                throw MimeTypeIsNotAllowedException::create($type);
            }
        }

        $maxBytes = $this->config['max_file_size'];
        $size = (int) $headers['Content-Length'][array_key_last($headers['Content-Length'])];

        if ($maxBytes >= 0 && $size > $maxBytes) {
            throw FileIsTooLargeException::create($maxBytes);
        }

        return true;
    }

    /**
     * Check for existence of a file from a storage disk.
     *
     * @throws MimeTypeIsNotAllowedException
     * @throws FileIsTooLargeException
     */
    protected function existsDisk(File $file): bool
    {
        $urlWithoutPort = $this->splitUrlByPort($file->getUrl())[1] ?? null;
        $exists = $this->getDisk($file)->exists($urlWithoutPort);

        if (!$exists) {
            return false;
        }

        if (!empty($this->config['mime_types'])) {
            $type = $this->getDisk($file)->mimeType($urlWithoutPort);
            if (!in_array($type, $this->config['mime_types'], true)) {
                throw MimeTypeIsNotAllowedException::create($type);
            }
        }

        $maxBytes = (int)$this->config['max_file_size'];

        if ($maxBytes >= 0) {
            $size = $this->getDisk($file)->size($urlWithoutPort);
            if ($size > $maxBytes) {
                throw FileIsTooLargeException::create($maxBytes);
            }
        }

        return true;
    }

    /**
     * Delete a cached file if it is not used.
     *
     * @param SplFileInfo $file
     *
     * @return bool If the file has been deleted.
     */
    protected function delete(SplFileInfo $file): bool
    {
        $fileStream = fopen($file->getRealPath(), 'rb');
        $deleted = false;

        try {
            // Only delete the file if it is not currently used. Else move on.
            if (flock($fileStream, LOCK_EX | LOCK_NB)) {
                $this->files->delete($file->getRealPath());
                $deleted = true;
            }
        } finally {
            fclose($fileStream);
        }

        return $deleted;
    }

    /**
     * Cache a remote or cloud storage file if it is not cached and get the path to
     * the cached file. If the file is local, nothing will be done and the path to the
     * local file will be returned.
     *
     * @return array Containing the 'path' to the file and the file 'stream'. Close the
     * stream when finished.
     *
     * @throws GuzzleException
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     * @throws MimeTypeIsNotAllowedException
     */
    protected function retrieve(File $file): array
    {
        $this->ensurePathExists();
        $cachedPath = $this->getCachedPath($file);

        // This will return false if the file already exists. Else it will create it in
        // read and write mode.
        $cachedFileStream = @fopen($cachedPath, 'xb+');

        if ($cachedFileStream === false) {
            // The file exists, get the file stream in read mode.
            $cachedFileStream = fopen($cachedPath, 'rb');
            // Wait for any LOCK_EX that is set if the file is currently written.
            flock($cachedFileStream, LOCK_SH);

            // Check if the file is still there since the writing operation could have
            // failed. If the file is gone, retry retrieve.
            if (fstat($cachedFileStream)['nlink'] === 0) {
                fclose($cachedFileStream);
                return $this->retrieve($file);
            }

            // The file exists and is no longer written to.
            return $this->retrieveExistingFile($cachedPath, $cachedFileStream);
        }

        // The file did not exist and should be written. Hold LOCK_EX until writing
        // finished.
        flock($cachedFileStream, LOCK_EX);

        try {
            $fileInfo = $this->retrieveNewFile($file, $cachedPath, $cachedFileStream);
            // Convert the lock so other workers can use the file from now on.
            flock($cachedFileStream, LOCK_SH);
        } catch (Exception $exception) {
            // Remove the empty file if writing failed. This is the case that is caught
            // by 'nlink' === 0 above.
            @unlink($cachedPath);
            fclose($cachedFileStream);

            throw $exception;
        }

        return $fileInfo;
    }

    /**
     * Get path and stream for a file that exists in the cache.
     *
     * @param string $cachedPath
     * @param resource $cachedFileStream
     *
     * @return array
     */
    protected function retrieveExistingFile(string $cachedPath, $cachedFileStream): array
    {
        // Update access and modification time to signal that this cached file was
        // used recently.
        touch($cachedPath);

        return [
            'path' => $cachedPath,
            'stream' => $cachedFileStream,
        ];
    }

    /**
     * Get path and stream for a file that does not yet exist in the cache.
     *
     * @param File $file
     * @param string $cachedPath
     * @param resource $cachedFileStream
     *
     * @return array
     *
     * @throws GuzzleException
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     * @throws MimeTypeIsNotAllowedException
     */
    protected function retrieveNewFile(File $file, string $cachedPath, $cachedFileStream): array
    {
        if ($this->isRemote($file)) {
            $cachedPath = $this->getRemoteFile($file, $cachedFileStream);
        } else {
            $newCachedPath = $this->getDiskFile($file, $cachedFileStream);

            // If it is a locally stored file, delete the empty "placeholder"
            // file again. The stream may stay open; it doesn't matter.
            if ($newCachedPath !== $cachedPath) {
                unlink($cachedPath);
            }

            $cachedPath = $newCachedPath;
        }

        if (!empty($this->config['mime_types'])) {
            $type = $this->files->mimeType($cachedPath);
            if (!in_array($type, $this->config['mime_types'], true)) {
                throw MimeTypeIsNotAllowedException::create($type);
            }
        }

        return [
            'path' => $cachedPath,
            'stream' => $cachedFileStream,
        ];
    }

    /**
     * Cache a remote file and get the path to the cached file.
     *
     * @param File $file Remote file
     * @param resource $target Target file resource
     *
     * @return string
     *
     * @throws GuzzleException
     * @throws FileIsTooLargeException
     */
    protected function getRemoteFile(File $file, $target): string
    {
        $cachedPath = $this->getCachedPath($file);

        $maxBytes = $this->config['max_file_size'];
        $isUnlimitedSize = $maxBytes === -1;
        $limitedTarget = new LimitStream(Utils::streamFor($target), $isUnlimitedSize ? -1 : $maxBytes + 1);
        $response = $this->client->get($this->encodeUrl($file->getUrl()), [
            'timeout' => $this->config['timeout'],
            'sink' => $limitedTarget,
        ]);
        $response->getBody()->detach();

        if (!$isUnlimitedSize && $limitedTarget->getSize() > $maxBytes) {
            throw FileIsTooLargeException::create($maxBytes);
        }

        return $cachedPath;
    }

    /**
     * Cache a file from a storage disk and get the path to the cached file. Files
     * from local disks are not cached.
     *
     * @param File $file Cloud storage file
     * @param resource $target Target file resource
     *
     * @return string
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     */
    protected function getDiskFile(File $file, $target): string
    {
        $path = $this->splitUrlByPort($file->getUrl())[1] ?? null;
        $disk = $this->getDisk($file);

        // Files from the local driver are not cached.
        $source = $disk->readStream($path);

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
     *
     * @return string Path to the cached file
     *
     * @throws SourceResourceIsInvalidException
     * @throws FileIsTooLargeException
     * @throws SourceResourceTimedOutException
     */
    protected function cacheFromResource(File $file, $source, $target): string
    {
        if (!is_resource($source)) {
            throw SourceResourceIsInvalidException::create('The source resource could not be established.');
        }

        $cachedPath = $this->getCachedPath($file);
        $maxBytes = $this->config['max_file_size'];
        $isUnlimitedSize = $maxBytes === -1;
        $bytes = stream_copy_to_stream($source, $target, $isUnlimitedSize ? -1 : $maxBytes + 1);

        if (!$isUnlimitedSize && $bytes > $maxBytes) {
            throw FileIsTooLargeException::create($maxBytes);
        }

        if ($bytes === false) {
            throw SourceResourceIsInvalidException::create();
        }

        $metadata = stream_get_meta_data($source);

        if (array_key_exists('timed_out', $metadata) && $metadata['timed_out']) {
            throw SourceResourceTimedOutException::create();
        }

        return $cachedPath;
    }

    /**
     * Get the path to the cached file.
     */
    protected function getCachedPath(File $file): string
    {
        $hash = hash('sha256', $file->getUrl());

        return "{$this->config['path']}/{$hash}";
    }

    /**
     * Get the storage disk on which a file is stored.
     */
    protected function getDisk(File $file): \Illuminate\Contracts\Filesystem\Filesystem
    {
        $diskName = $this->splitUrlByPort($file->getUrl())[0] ?? null;

        // Throws an exception if the disk does not exist.
        return $this->storage->disk($diskName);
    }

    protected function prepareConfig(array $config): array
    {
        $config['max_file_size'] = (int)$config['max_file_size'];
        $config['max_age'] = (int)$config['max_age'];
        $config['max_size'] = (int)$config['max_size'];
        $config['timeout'] = (float)$config['timeout'];
        $config['mime_types'] = (array)($config['mime_types'] ?? []);

        return $config;
    }

    /**
     * Creates the cache directory if it doesn't exist yet.
     */
    protected function ensurePathExists(): void
    {
        if (!$this->files->exists($this->config['path'])) {
            $this->files->makeDirectory($this->config['path'], 0755, true, true);
        }
    }

    /**
     * Determine if a file is remote, i.e. served by a public webserver.
     *
     * @param File $file
     *
     * @return boolean
     */
    protected function isRemote(File $file): bool
    {
        return str_starts_with($file->getUrl(), 'http');
    }

    protected function splitUrlByPort(string $url): array
    {
        return explode('://', $url, 2);
    }

    /**
     * Escape special characters (e.g. spaces) that may occur in parts of a HTTP URL.
     * We do not use urlencode or rawurlencode because they encode some characters
     * (e.g. "+") that should not be changed in the URL.
     */
    protected function encodeUrl(string $url): string
    {
        // List of characters to substitute and their replacements at the same index.
        $pattern = [' '];
        $replacement = ['%20'];

        return str_replace($pattern, $replacement, $url);
    }

    protected static function defaultGetCallback(File $file, string $path): string
    {
        return $path;
    }

    protected static function defaultBatchCallback(array $files, array $paths): array
    {
        return $paths;
    }
}
