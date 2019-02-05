<?php

namespace Biigle\FileCache;

use Biigle\FileCache\Contracts\File;

class GenericFile implements File
{
    /**
     * The file URL.
     *
     * @var string
     */
    protected $url;

    /**
     * Create a new instance.
     *
     * @param string $url
     */
    public function __construct($url)
    {
        $this->url = $url;
    }
    /**
     * {@inheritdoc}
     */
    public function getUrl()
    {
        return $this->url;
    }
}
