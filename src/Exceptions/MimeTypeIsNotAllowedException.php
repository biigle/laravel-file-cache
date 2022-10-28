<?php

namespace Biigle\FileCache\Exceptions;

use Exception;

class MimeTypeIsNotAllowedException extends Exception
{
    public static function create(string $mimeType): self
    {
        return new self("MIME type '{$mimeType}' not allowed.");
    }
}
