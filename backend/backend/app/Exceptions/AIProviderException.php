<?php

namespace App\Exceptions;

use Exception;

class AIProviderException extends Exception
{
    public static function forProvider(string $provider, string $detail): self
    {
        return new self("AI provider '{$provider}' failed: {$detail}");
    }
}
