<?php

namespace Biigle\FileCache\Exceptions;

use Exception;

class SourceResourceIsInvalidException extends Exception
{
    public static function create(?string $message = null): self
    {
        return new self($message ?? 'The source resource is invalid.');
    }
}
