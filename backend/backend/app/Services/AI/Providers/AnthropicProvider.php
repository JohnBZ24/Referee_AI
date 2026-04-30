<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\AIProvider;

/**
 * Streams completions from the Anthropic Messages API using cURL.
 * Parses the provider's SSE format: `event: content_block_delta / data: {...}`.
 */
class AnthropicProvider implements AIProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $modelId = 'claude-3-5-sonnet-20241022',
        private readonly string $name = 'Claude 3.5 Sonnet',
    ) {}

    public function buildStreamHandle(string $prompt, callable $onChunk, array &$meta): \CurlHandle
    {
        $meta = ['tokens' => 0];

        $ch = curl_init();
        $buffer = '';

        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.anthropic.com/v1/messages',
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'x-api-key: '.$this->apiKey,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->modelId,
                'max_tokens' => 2048,
                'stream' => true,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => (int) config('ai.timeout', 120),
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($onChunk, &$buffer, &$meta): int {
                $buffer .= $data;

                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $block = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);

                    foreach (explode("\n", $block) as $line) {
                        if (! str_starts_with($line, 'data: ')) {
                            continue;
                        }

                        $json = substr($line, 6);

                        if ($json === '[DONE]') {
                            continue;
                        }

                        $decoded = json_decode($json, true);

                        if (! is_array($decoded)) {
                            continue;
                        }

                        if (($decoded['type'] ?? '') === 'content_block_delta') {
                            $text = $decoded['delta']['text'] ?? '';
                            if ($text !== '') {
                                $onChunk($text);
                            }
                        }

                        if (($decoded['type'] ?? '') === 'message_delta') {
                            $meta['tokens'] = $decoded['usage']['output_tokens'] ?? 0;
                        }
                    }
                }

                return strlen($data);
            },
        ]);

        return $ch;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getModelId(): string
    {
        return $this->modelId;
    }

    public function getProviderName(): string
    {
        return 'anthropic';
    }
}
