<?php

namespace Biigle\ImageCache;

use Biigle\ImageCache\Contracts\Image;

class GenericImage implements Image
{
    /**
     * The image URL.
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
