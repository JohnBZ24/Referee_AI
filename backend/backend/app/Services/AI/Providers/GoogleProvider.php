<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\AIProvider;

/**
 * Streams completions from Google's Gemini API (streamGenerateContent endpoint).
 * Response is a JSON array of candidate objects streamed as newline-delimited JSON.
 */
class GoogleProvider implements AIProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $modelId = 'gemini-1.5-pro',
        private readonly string $name = 'Gemini 1.5 Pro',
    ) {}

    public function buildStreamHandle(string $prompt, callable $onChunk, array &$meta): \CurlHandle
    {
        $meta = ['tokens' => 0];

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:streamGenerateContent?alt=sse&key=%s',
            $this->modelId,
            $this->apiKey,
        );

        $ch = curl_init();
        $buffer = '';

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
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

                        $decoded = json_decode(substr($line, 6), true);

                        if (! is_array($decoded)) {
                            continue;
                        }

                        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
                        if ($text !== '') {
                            $onChunk($text);
                        }

                        if (isset($decoded['usageMetadata']['candidatesTokenCount'])) {
                            $meta['tokens'] = $decoded['usageMetadata']['candidatesTokenCount'];
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
        return 'google';
    }
}
