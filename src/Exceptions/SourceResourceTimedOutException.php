<?php

namespace Biigle\FileCache\Exceptions;

use Exception;

class SourceResourceTimedOutException extends Exception
{
    public static function create(?string $message = null): self
    {
        return new self($message ?? 'The source stream timed out while reading data.');
    }
}
