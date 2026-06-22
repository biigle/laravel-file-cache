<?php

namespace Biigle\FileCache;

use Biigle\FileCache\Contracts\File;
use Biigle\FileCache\Contracts\FileCache as FileCacheContract;
use Biigle\FileCache\Exceptions\FileLockedException;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
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
     * Name of the subdirectory in the cache path that holds the per-process
     * lock files.
     *
     * @var string
     */
    const LOCK_DIR = '.locks';

    /**
     * Name of the subdirectory in the cache path that holds the reference links.
     *
     * @var string
     */
    const REFS_DIR = '.refs';

    /**
     * Maximum number of times retrieve() retries when the cached file keeps
     * disappearing or is left empty by a failing writer. Bounds the retries so
     * a persistently failing file cannot loop forever.
     *
     * @var integer
     */
    const MAX_RETRIEVE_ATTEMPTS = 10;

    /**
     * Counter for reference links used to generate unique names per instance.
     *
     * @var integer
     */
    protected $linkCount = 0;

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
     * Open file handle holding the exclusive lock that signals this process is
     * alive. Created lazily and held for the lifetime of the process.
     *
     * @var resource|null
     */
    protected $lockHandle;

    /**
     * Unique token identifying this process. Embedded in every reference link
     * name and used as the name of this process' lock file.
     *
     * @var string|null
     */
    protected $lockToken;

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
     * Release the process lock when the instance is destroyed. If this does not
     * run (e.g. on a crash) the kernel releases the lock anyway and the orphan
     * lock file is reaped by the pruner.
     */
    public function __destruct()
    {
        if (!is_resource($this->lockHandle)) {
            return;
        }

        // Also releases the LOCK_EX.
        fclose($this->lockHandle);
        @unlink($this->lockPath($this->lockToken));
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
            try {
                return $this->getFileStream($file->getUrl());
            } catch (BadResponseException $e) {
                throw new Exception("The file does not exist", previous: $e);
            }
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
        return $this->runBatch($files, $callback, $throwOnLock, function ($file) {
            // Update the atime so the pruner knows when the file was last used.
            // May be relevant for (hours-)long batches.
            @touch($file['link']);
            @unlink($file['link']);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function batchOnce(array $files, callable $callback, bool $throwOnLock = false)
    {
        return $this->runBatch($files, $callback, $throwOnLock, function ($file) {
            @unlink($file['link']);
            $this->delete(new SplFileInfo($file['path']));
        });
    }

    /**
     * Retrieve the files, run the callback with their paths and clean up the
     * reference links afterwards.
     *
     * @param File[] $files
     * @param callable $callback
     * @param bool $throwOnLock
     * @param callable $cleanup Cleanup applied to each retrieved file that has a
     * reference link (i.e. is not locally stored).
     *
     * @return mixed The return value of the callback.
     */
    protected function runBatch(array $files, callable $callback, bool $throwOnLock, callable $cleanup)
    {
        $retrieved = [];

        try {
            // Must be a loop so $retrieved is populated incrementally. If retrieve()
            // throws, the finally block can still clean up any links created so far.
            foreach ($files as $index => $file) {
                $retrieved[$index] = $this->retrieve($file, $throwOnLock);
            }

            $paths = array_map(function ($file) {
                return $file['path'];
            }, $retrieved);

            $result = call_user_func($callback, $files, $paths);
        } finally {
            foreach ($retrieved as $file) {
                // Locally stored files have no reference link and must not be
                // deleted.
                if (is_null($file['link'])) {
                    continue;
                }

                $cleanup($file);
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

        // Remove reference links and lock files of crashed workers so that
        // files only referenced by dead workers become eligible for pruning.
        $this->pruneStaleReferences();
        $this->pruneOrphanLockFiles();

        $currentSize = $this->pruneFilesByMaxAge();

        $this->pruneFilesByMaxSize($currentSize);
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        if (!$this->files->exists($this->config['path'])) {
            return;
        }

        // Remove reference links and lock files of crashed workers so that
        // files only referenced by dead workers become eligible for deletion.
        $this->pruneStaleReferences();
        $this->pruneOrphanLockFiles();

        $files = $this->canonicalFiles()->getIterator();

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
        try {
            $response = $this->client->head($file->getUrl());
        } catch (BadResponseException $e) {
            return false;
        }

        $code = $response->getStatusCode();

        if ($code < 200 || $code >= 300) {
            return false;
        }

        if (!empty($this->config['mime_types'])) {
            $type = $response->getHeaderLine('content-type');
            $type = trim(explode(';', $type)[0]);
            $this->assertMimeTypeAllowed($type);
        }

        $maxBytes = intval($this->config['max_file_size']);

        if ($maxBytes >= 0) {
            $contentLength = $response->getHeaderLine('content-length');

            $contentLength = filter_var($contentLength, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);

            if ($contentLength === false) {
                throw new Exception("File size could not be determined (missing or invalid content-length header).");
            }

            $this->assertSizeAllowed($contentLength);
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
        $disk = $this->getDisk($file);
        $exists = $disk->exists($url[1]);

        if (!$exists) {
            return false;
        }

        if (!empty($this->config['mime_types'])) {
            $this->assertMimeTypeAllowed($disk->mimeType($url[1]));
        }

        $maxBytes = intval($this->config['max_file_size']);

        if ($maxBytes >= 0) {
            $this->assertSizeAllowed($disk->size($url[1]));
        }

        return true;
    }

    /**
     * Throw if the given MIME type is not in the configured allow list.
     *
     * @param string $type
     * @throws Exception If the MIME type is not allowed.
     */
    protected function assertMimeTypeAllowed($type)
    {
        $allowed = $this->config['mime_types'];
        if (!empty($allowed) && !in_array($type, $allowed)) {
            throw new Exception("MIME type '{$type}' not allowed.");
        }
    }

    /**
     * Throw if the given file size exceeds the configured max_file_size. A
     * negative max_file_size disables the check.
     *
     * @param int $size Size in bytes.
     * @throws Exception If the file is too large.
     */
    protected function assertSizeAllowed($size)
    {
        $maxBytes = intval($this->config['max_file_size']);

        if ($maxBytes >= 0 && $size > $maxBytes) {
            throw new Exception("The file is too large with more than {$maxBytes} bytes.");
        }
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
        $path = $file->getRealPath();
        // The file may already be gone (e.g. pruned concurrently).
        if ($path === false) {
            return false;
        }

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return false;
        }

        $deleted = false;

        try {
            $stat = fstat($handle);
            // Reference links exist, so the file is still in use. This is an optimization
            // and not strictly required as the check is performed again below.
            if ($stat && $stat['nlink'] > 1) {
                return false;
            }

            // Only delete the file if it is not currently used. Else move on.
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                // Re-check after acquiring the lock to guard the race between
                // the nlink check and the lock acquisition.
                $stat = fstat($handle);
                if ($stat && $stat['nlink'] === 1) {
                    $this->files->delete($path);
                    $deleted = true;
                }
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
     * @param int $attempt Current retry attempt. Used internally to bound the recursion.
     * @throws Exception If the file could not be cached.
     * @throws FileLockedException If the file is locked and `throwOnLock` was `true`.
     *
     * @return array Containing the 'path' to the file and the reference 'link'
     * (or `null` for locally stored files). Unlink the reference link when
     * finished.
     */
    protected function retrieve(File $file, bool $throwOnLock = false, int $attempt = 0)
    {
        // A file that keeps disappearing or is repeatedly left empty by a failing
        // writer must not recurse forever.
        if ($attempt >= self::MAX_RETRIEVE_ATTEMPTS) {
            throw new Exception("Failed to retrieve file '{$file->getUrl()}' after ".self::MAX_RETRIEVE_ATTEMPTS." attempts.");
        }

        $this->ensureDirExists($this->config['path']);
        $cachedPath = $this->getCachedPath($file);

        // This will return false if the file already exists. Else it will create it in
        // read and write mode.
        $handle = @fopen($cachedPath, 'x+');

        if ($handle === false) {
            // The file exists, get the file handle in read mode.
            $handle = @fopen($cachedPath, 'r');
            // The cached file may be deleted between the first fopen and now. Retry.
            if ($handle === false) {
                return $this->retrieve($file, $throwOnLock, $attempt + 1);
            }

            if ($throwOnLock && !flock($handle, LOCK_SH | LOCK_NB)) {
                fclose($handle);
                throw new FileLockedException;
            }

            // Wait for any LOCK_EX that is set if the file is currently written.
            flock($handle, LOCK_SH);

            $stat = fstat($handle);
            if ($stat === false) {
                fclose($handle);
                throw new RuntimeException("Could not stat cached file '{$cachedPath}'.");
            }

            // Check if the file is still there since the writing operation could have
            // failed. If the file is gone, retry retrieve.
            if ($stat['nlink'] === 0) {
                fclose($handle);
                return $this->retrieve($file, $throwOnLock, $attempt + 1);
            }

            // File caching may have failed and left an empty file in the cache.
            // Delete the empty file and try to cache the file again.
            if ($stat['size'] === 0) {
                fclose($handle);
                $this->delete(new SplFileInfo($cachedPath));
                return $this->retrieve($file, $throwOnLock, $attempt + 1);
            }

            // The file exists and is no longer written to.
            return $this->retrieveExistingFile($cachedPath, $handle);
        }

        // The file did not exist and should be written. Hold LOCK_EX until writing
        // finished.
        flock($handle, LOCK_EX);

        // Between creating the file with 'x+' and acquiring the lock, another
        // process may have grabbed a shared lock, seen the empty file and deleted
        // it. So we check again if the file exists after having the lock.
        $stat = fstat($handle);
        if ($stat === false) {
            fclose($handle);
            throw new RuntimeException("Could not stat cached file '{$cachedPath}'.");
        }

        if ($stat['nlink'] === 0) {
            fclose($handle);
            return $this->retrieve($file, $throwOnLock, $attempt + 1);
        }

        try {
            return $this->retrieveNewFile($file, $cachedPath, $handle);
        } catch (Exception $e) {
            // Remove the empty file if writing failed. This is the case that is caught
            // by 'nlink' === 0 above.
            @unlink($cachedPath);
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new Exception("Error while caching file '{$file->getUrl()}': {$e->getMessage()}");
        }
    }

    /**
     * Get path and reference link for a file that exists in the cache.
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
            'link' => $this->createReferenceLink($cachedPath, $handle),
        ];
    }

    /**
     * Get path and reference link for a file that does not yet exist in the cache.
     *
     * @param File $file
     * @param string $cachedPath
     * @param resource $handle
     *
     * @return array
     */
    protected function retrieveNewFile(File $file, $cachedPath, $handle)
    {
        $isLocal = false;

        if ($this->isRemote($file)) {
            $source = $this->getFileStream($file->getUrl());
            try {
                $cachedPath = $this->cacheFromResource($file, $source, $handle);
            } finally {
                if (is_resource($source)) {
                    fclose($source);
                }
            }
        } else {
            $newCachedPath = $this->getDiskFile($file, $handle);

            // If it is a locally stored file, delete the empty "placeholder"
            // file again. The handle is closed below.
            if ($newCachedPath !== $cachedPath) {
                unlink($cachedPath);
                // Locally stored files are not managed by the cache, so they
                // must not be reference-linked or deleted.
                $isLocal = true;
            }

            $cachedPath = $newCachedPath;
        }

        if (!empty($this->config['mime_types'])) {
            $this->assertMimeTypeAllowed($this->files->mimeType($cachedPath));
        }

        $link = null;

        if ($isLocal) {
            fclose($handle);
        } else {
            // Convert the lock so other workers can use the file immediately.
            flock($handle, LOCK_SH);

            // Non-local (cached) files get a reference link that exists as long as the
            // file is needed (e.g. during batch()).
            $link = $this->createReferenceLink($cachedPath, $handle);
        }

        return [
            'path' => $cachedPath,
            'link' => $link,
        ];
    }

    /**
     * Create the reference link that signals the cached file is in use and
     * release the lock handle.
     *
     * @param string $cachedPath
     * @param resource $handle
     *
     * @return string Path to the reference link.
     */
    protected function createReferenceLink($cachedPath, $handle): string
    {
        // Acquire the process lock before creating the reference link so the
        // link always has a live, lock-holding owner from the moment it
        // exists.
        $this->ensureProcessLock();

        $dir = $this->refsDir();
        $this->ensureDirExists($dir);

        // The token identifies the owning process. The suffix keeps the
        // name unique across multiple references held by the same process.
        $link = "{$dir}/{$this->lockToken}.{$this->linkCount}";
        $this->linkCount += 1;
        // Create the reference link while the lock on the canonical file is
        // still held so the pruner cannot delete it before the link exists.
        $success = @link($cachedPath, $link);

        fclose($handle);

        if (!$success) {
            throw new RuntimeException("Failed to create reference link '{$link}' for cached file '{$cachedPath}'.");
        }

        return $link;
    }

    /**
     * Get a Finder for the canonical cache files, excluding reference links and
     * lock files.
     *
     * @return Finder
     */
    protected function canonicalFiles(): Finder
    {
        return Finder::create()
            ->files()
            ->ignoreDotFiles(true)
            ->exclude(self::REFS_DIR)
            ->exclude(self::LOCK_DIR)
            ->in($this->config['path']);
    }

    /**
     * Extract the owning process' lock token from a reference link filename.
     *
     * @param string $filename
     *
     * @return string
     */
    protected function referenceLinkToken($filename)
    {
        return explode('.', $filename)[0] ?: '';
    }

    /**
     * Acquire the lock that signals this process is alive, holding it for the
     * lifetime of the process.
     *
     * The lock is created lazily the first time a reference link is needed. The
     * kernel releases it when the process exits (including crashes), which is
     * how the pruner detects that this worker's reference links are stale.
     * flock also works across (Docker) containers sharing the cache volume because
     * the lock lives in the host kernel on the shared inode.
     */
    protected function ensureProcessLock()
    {
        if (is_resource($this->lockHandle)) {
            return;
        }

        $dir = $this->lockDir();
        $this->ensureDirExists($dir);

        // A token collision is extremely unlikely, no need to guard.
        $token = bin2hex(random_bytes(16));
        $path = $this->lockPath($token);
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException("Could not create lock file at {$path}");
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            @unlink($path);
            throw new RuntimeException("Could not get lock on file {$path}");
        }

        $this->lockToken = $token;
        $this->lockHandle = $handle;
    }

    /**
     * Determine whether the process owning the given lock token is still alive.
     *
     * @return bool
     */
    protected function isProcessAlive(string $token)
    {
        // This process obviously is alive.
        if ($token === $this->lockToken) {
            return true;
        }

        $handle = @fopen($this->lockPath($token), 'r');
        // No lock file means the owning process is gone.
        if ($handle === false) {
            return false;
        }

        try {
            // If the exclusive lock can be acquired, the owning process no
            // longer holds it and has therefore terminated. The probe lock is
            // released by the fclose below.
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return false;
            }
        } finally {
            fclose($handle);
        }

        return true;
    }

    /**
     * Remove reference links left behind by crashed workers.
     */
    protected function pruneStaleReferences()
    {
        $dir = $this->refsDir();
        if (!$this->files->exists($dir)) {
            return;
        }

        $alive = [];
        $links = Finder::create()
            ->files()
            ->in($dir)
            ->filter(function (SplFileInfo $file) use (&$alive) {
                $token = $this->referenceLinkToken($file->getFilename());
                // We only want to delete references of dead processes.
                // Memoize per token since a worker may hold many links.
                if (!array_key_exists($token, $alive)) {
                    $alive[$token] = $this->isProcessAlive($token);
                }

                return !$alive[$token];
            })
            ->getIterator();

        foreach ($links as $link) {
            if (($path = $link->getRealPath())) {
                @unlink($path);
            }
        }
    }

    /**
     * Prune canonical files that are older than max_age.
     *
     * @return int The total size in bytes of the remaining files.
     */
    protected function pruneFilesByMaxAge(): int
    {
        $now = time();
        // Allowed age in seconds.
        $allowedAge = $this->config['max_age'] * 60;
        $totalSize = 0;

        $files = $this->canonicalFiles()->getIterator();

        // Prune files by age.
        foreach ($files as $file) {
            try {
                $aTime = $file->getATime();
                $size = $file->getSize();
            } catch (RuntimeException $e) {
                // This can happen if the file is deleted in the meantime.
                continue;
            }

            if (($now - $aTime) > $allowedAge && $this->delete($file)) {
                continue;
            }

            $totalSize += $size;
        }

        return $totalSize;
    }

    /**
     * Prune oldest canonical files that exceed the max_size.
     *
     * @param int $currentSize Current total size of the files.
     */
    protected function pruneFilesByMaxSize(int $currentSize)
    {
        $allowedSize = $this->config['max_size'];

        if ($currentSize <= $allowedSize) {
            return;
        }

        // This will return the least recently accessed files first.
        // We use a custom sorting function which ignores errors (because files may
        // have been deleted in the meantime).
        $files = $this->canonicalFiles()
            ->sort(function (SplFileInfo $a, SplFileInfo $b) {
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
            })
            ->getIterator();

        foreach ($files as $file) {
            if ($currentSize <= $allowedSize) {
                break;
            }

            try {
                $fileSize = $file->getSize();
            } catch (RuntimeException $e) {
                // This can happen if the file is deleted in the meantime.
                continue;
            }

            if ($this->delete($file)) {
                $currentSize -= $fileSize;
            }
        }
    }

    /**
     * Remove lock files of processes that have terminated.
     */
    protected function pruneOrphanLockFiles()
    {
        $dir = $this->lockDir();
        if (!$this->files->exists($dir)) {
            return;
        }

        // Only consider lock files older than 1 s to rule out the brief window
        // between creating a fresh lock file and acquiring its lock.
        $maxMTime = time() - 1;

        $lockFiles = Finder::create()
            ->files()
            ->in($dir)
            ->filter(function (SplFileInfo $file) use ($maxMTime) {
                try {
                    $mTime = $file->getMTime();
                } catch (RuntimeException $e) {
                    // The lock file may have been deleted concurrently.
                    return false;
                }

                if ($mTime > $maxMTime) {
                    return false;
                }

                return !$this->isProcessAlive($file->getFilename());
            })
            ->getIterator();

        foreach ($lockFiles as $file) {
            if (($path = $file->getRealPath())) {
                @unlink($path);
            }
        }
    }

    /**
     * Get the directory in which process lock files are stored.
     *
     * @return string
     */
    protected function lockDir()
    {
        return "{$this->config['path']}/".self::LOCK_DIR;
    }

    /**
     * Get the directory in which reference links are stored.
     *
     * @return string
     */
    protected function refsDir()
    {
        return "{$this->config['path']}/".self::REFS_DIR;
    }

    /**
     * Get the path to the lock file for the given process token.
     *
     * @param string $token
     *
     * @return string
     */
    protected function lockPath($token)
    {
        return "{$this->lockDir()}/{$token}";
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

        // Files of the local driver are not cached but used in place. Flysystem 3
        // no longer exposes the adapter, so the driver is read from the disk
        // config instead.
        $driver = $disk->getConfig()['driver'] ?? null;
        if ($driver === 'local') {
            if (!$disk->exists($url[1])) {
                throw new Exception('File does not exist.');
            }

            return $disk->path($url[1]);
        }

        $source = $disk->readStream($url[1]);
        if (is_null($source)) {
            throw new Exception('File does not exist.');
        }

        try {
            $cachedPath = $this->cacheFromResource($file, $source, $target);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
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
        // Copy one byte more than allowed to detect files that exceed the limit.
        // Using $maxBytes directly would reject files of exactly $maxBytes bytes.
        // Clamp at PHP_INT_MAX to avoid overflowing to float for very large limits.
        if ($maxBytes < 0) {
            $copyLimit = -1;
        } elseif ($maxBytes < PHP_INT_MAX) {
            $copyLimit = $maxBytes + 1;
        } else {
            $copyLimit = PHP_INT_MAX;
        }
        $bytes = stream_copy_to_stream($source, $target, $copyLimit);

        if ($bytes === false) {
            throw new Exception('The source resource is invalid.');
        }

        $this->assertSizeAllowed($bytes);

        $metadata = stream_get_meta_data($source);

        if (array_key_exists('timed_out', $metadata) && $metadata['timed_out']) {
            throw new Exception('The source stream timed out while reading data.');
        }

        return $cachedPath;
    }

    /**
     * Creates the directory if it doesn't exist yet.
     */
    protected function ensureDirExists(string $dir)
    {
        if (!$this->files->exists($dir)) {
            $this->files->makeDirectory($dir, 0755, true, true);
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
            return $this->client->get($url, ['stream' => true])->getBody()->detach();
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
        ]);
    }
}
