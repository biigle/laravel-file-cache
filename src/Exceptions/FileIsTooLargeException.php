<?php

namespace Biigle\FileCache\Exceptions;

use Exception;

class FileIsTooLargeException extends Exception
{
    public static function create(int $maxBytes): self
    {
        return new self("The file is too large with more than {$maxBytes} bytes.");
    }
}
