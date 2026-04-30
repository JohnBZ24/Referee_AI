<?php

namespace App\Exceptions;

use Exception;

class InvalidModelException extends Exception
{
    public static function forSlug(string $slug): self
    {
        return new self("Model '{$slug}' is not configured.");
    }
}
