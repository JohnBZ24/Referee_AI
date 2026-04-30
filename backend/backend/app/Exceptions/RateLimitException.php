<?php

namespace App\Exceptions;

use Exception;

class RateLimitException extends Exception
{
    public static function forProvider(string $provider): self
    {
        return new self("Rate limit exceeded for provider '{$provider}'.");
    }
}
