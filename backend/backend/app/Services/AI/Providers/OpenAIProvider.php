<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\AIProvider;

/**
 * Streams completions from the OpenAI Chat Completions API using cURL.
 * Parses the OpenAI SSE format: `data: {"choices":[{"delta":{"content":"..."}}]}`.
 */
class OpenAIProvider implements AIProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $modelId = 'gpt-4o',
        private readonly string $name = 'GPT-4o',
    ) {}

    public function buildStreamHandle(string $prompt, callable $onChunk, array &$meta): \CurlHandle
    {
        $meta = ['tokens' => 0];

        $ch = curl_init();
        $buffer = '';

        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.openai.com/v1/chat/completions',
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->modelId,
                'stream' => true,
                'stream_options' => ['include_usage' => true],
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

                        $text = $decoded['choices'][0]['delta']['content'] ?? '';
                        if ($text !== '') {
                            $onChunk($text);
                        }

                        if (isset($decoded['usage']['completion_tokens'])) {
                            $meta['tokens'] = $decoded['usage']['completion_tokens'];
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
        return 'openai';
    }
}
