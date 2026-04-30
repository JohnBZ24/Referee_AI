<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\AIProvider;

/**
 * Streams completions from OpenRouter (OpenAI-compatible SSE).
 */
class OpenRouterProvider implements AIProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $modelId,
        private readonly string $name = 'OpenRouter',
    ) {}

    public function buildStreamHandle(string $prompt, callable $onChunk, array &$meta): \CurlHandle
    {
        return $this->buildStreamHandleFromPayload([
            'model' => $this->modelId,
            'stream' => true,
            // Some OpenRouter models reject extra fields; keep payload minimal.
            'stream_options' => ['include_usage' => true],
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ], $onChunk, $meta);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function buildStreamHandleFromPayload(array $payload, callable $onChunk, array &$meta): \CurlHandle
    {
        $meta = ['tokens' => 0, 'raw' => ''];

        $ch = curl_init();
        $buffer = '';

        $headers = [
            'Authorization: Bearer '.$this->apiKey,
            'Content-Type: application/json',
        ];

        $referer = (string) config('app.url');
        if ($referer !== '') {
            $headers[] = 'HTTP-Referer: '.$referer;
        }

        $appName = (string) config('app.name');
        if ($appName !== '') {
            $headers[] = 'X-Title: '.$appName;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://openrouter.ai/api/v1/chat/completions',
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => (int) config('ai.timeout', 120),
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($onChunk, &$buffer, &$meta): int {
                // Keep a bounded copy of raw stream for debugging errors.
                if (is_string($data) && $data !== '') {
                    $meta['raw'] .= $data;
                    if (strlen($meta['raw']) > 20000) {
                        $meta['raw'] = substr($meta['raw'], -20000);
                    }
                }

                $buffer .= $data;

                // Parse SSE blocks. Some providers send CRLF (\r\n) delimiters.
                while (true) {
                    $posLf = strpos($buffer, "\n\n");
                    $posCrLf = strpos($buffer, "\r\n\r\n");

                    $pos = false;
                    $delimLen = 0;

                    if ($posLf !== false && $posCrLf !== false) {
                        if ($posLf <= $posCrLf) {
                            $pos = $posLf;
                            $delimLen = 2;
                        } else {
                            $pos = $posCrLf;
                            $delimLen = 4;
                        }
                    } elseif ($posLf !== false) {
                        $pos = $posLf;
                        $delimLen = 2;
                    } elseif ($posCrLf !== false) {
                        $pos = $posCrLf;
                        $delimLen = 4;
                    }

                    if ($pos === false) {
                        break;
                    }

                    $block = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + $delimLen);

                    // Normalize line endings.
                    $block = str_replace("\r\n", "\n", $block);

                    foreach (explode("\n", $block) as $line) {
                        $line = trim($line);
                        if ($line === '' || ! str_starts_with($line, 'data:')) {
                            continue;
                        }

                        $payload = trim(substr($line, 5));
                        if ($payload === '' || $payload === '[DONE]') {
                            continue;
                        }

                        $decoded = json_decode($payload, true);
                        if (! is_array($decoded)) {
                            continue;
                        }

                        $delta = $decoded['choices'][0]['delta'] ?? [];

                        // Standard OpenAI chat deltas.
                        $text = is_array($delta) ? ($delta['content'] ?? '') : '';
                        if (is_string($text) && $text !== '') {
                            $onChunk($text);
                        }

                        // Some models emit output in other fields (rare, but defensive).
                        $alt = is_array($delta) ? ($delta['text'] ?? '') : '';
                        if (is_string($alt) && $alt !== '') {
                            $onChunk($alt);
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
        return 'openrouter';
    }
}
