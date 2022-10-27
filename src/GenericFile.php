<?php

namespace Biigle\FileCache;

use Biigle\FileCache\Contracts\File;

class GenericFile implements File
{
    /**
     * The file URL.
     */
    protected string $url;

    /**
     * Create a new instance.
     */
    public function __construct(string $url)
    {
        $this->url = $url;
    }
    /**
     * {@inheritdoc}
     */
    public function getUrl(): string
    {
        return $this->url;
    }
}
