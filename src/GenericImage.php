<?php

namespace Biigle\ImageCache;

use Biigle\ImageCache\Contracts\Image;

class GenericImage implements Image
{
    /**
     * The image ID.
     *
     * @var int
     */
    protected $id;

    /**
     * The image URL.
     *
     * @var string
     */
    protected $url;

    /**
     * Create a new instance.
     *
     * @param int $id
     * @param string $url
     */
    public function __construct($id, $url)
    {
        $this->id = $id;
        $this->url = $url;
    }

    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getUrl()
    {
        return $this->url;
    }
}
