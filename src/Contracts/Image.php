<?php

namespace Biigle\ImageCache\Contracts;

interface Image
{
    /**
    * Get the image ID.
    *
    * @return int
    */
   public function getId();

   /**
    * Get the image URL.
    *
    * This may be a remote URL starting with "http://" or "https://", or a storage disk
    * path starting with "[disk-name]://".
    *
    * @return string
    */
   public function getUrl();
}
