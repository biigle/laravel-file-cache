<?php

namespace Biigle\FileCache\Contracts;

interface File
{
    /**
     * Get the file URL.
     *
     * This may be a remote URL starting with "http://" or "https://", or a storage disk
     * path starting with "[disk-name]://".
     *
     * @return string
     */
    public function getUrl();
}
