<?php

namespace App\Services\AI\Contracts;

interface AIProvider
{
    /**
     * Build a cURL handle configured to stream completions.
     * The write-function callback calls $onChunk(string $text) for each text fragment.
     *
     * @param  callable(string): void  $onChunk
     */
    public function buildStreamHandle(string $prompt, callable $onChunk, array &$meta): \CurlHandle;

    public function getName(): string;

    public function getModelId(): string;

    public function getProviderName(): string;
}
