<?php

namespace App\Services\AI;

use App\Exceptions\AIProviderException;
use App\Exceptions\InvalidModelException;
use App\Services\AI\Contracts\AIProvider;
use App\Services\AI\Providers\AnthropicProvider;
use App\Services\AI\Providers\GoogleProvider;
use App\Services\AI\Providers\MoonshotProvider;
use App\Services\AI\Providers\OpenAIProvider;
use App\Services\AI\Providers\OpenRouterProvider;

class AIService
{
    /**
     * Detect OpenRouter PDF parser failures so we can retry with a different engine.
     */
    protected function isOpenRouterPdfParseFailure(int $httpCode, string $detail): bool
    {
        if ($httpCode !== 400) {
            return false;
        }

        return stripos($detail, 'Failed to parse') !== false;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $plugins
     * @return array<int, array<string, mixed>>|null
     */
    protected function rewriteOpenRouterPdfEngine(?array $plugins, string $engine): ?array
    {
        if (! is_array($plugins) || $plugins === []) {
            return $plugins;
        }

        $next = [];
        foreach ($plugins as $plugin) {
            if (! is_array($plugin)) {
                $next[] = $plugin;

                continue;
            }

            $id = $plugin['id'] ?? null;
            if ($id !== 'file-parser') {
                $next[] = $plugin;

                continue;
            }

            $pdf = $plugin['pdf'] ?? null;
            if (! is_array($pdf)) {
                $next[] = $plugin;

                continue;
            }

            $pdf['engine'] = $engine;
            $plugin['pdf'] = $pdf;
            $next[] = $plugin;
        }

        return $next;
    }

    protected function openRouterPdfFallbackEngine(): ?string
    {
        $value = (string) config('ai.openrouter_pdf_engine_fallback', 'mistral-ocr');
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * Stream panelist models sequentially to avoid rate limiting.
     *
     * @param  array<string>  $modelSlugs  Keys from config('ai.models')
     * @param  callable(int $index, string $chunk): void  $onChunk
     * @param  callable(int $index, int $tokens, string $content): void  $onComplete
     * @return array<int, string> Full responses indexed by position
     */
    public function streamParallel(
        array $modelSlugs,
        string $prompt,
        callable $onChunk,
        callable $onComplete,
        ?callable $onError = null,
    ): array {
        $responses = [];

        $multi = curl_multi_init();
        $handles = [];
        $metas = [];

        foreach ($modelSlugs as $index => $slug) {
            error_log("[AI Debug] Starting request {$index}: {$slug}");

            $provider = $this->resolveProvider($slug);
            $metas[$index] = [];
            $responses[$index] = '';

            $ch = $provider->buildStreamHandle(
                $prompt,
                function (string $chunk) use ($index, $onChunk, &$responses): void {
                    $responses[$index] .= $chunk;
                    $onChunk($index, $chunk);
                },
                $metas[$index],
            );

            $handles[$index] = $ch;
            curl_multi_add_handle($multi, $ch);
        }

        $running = null;
        do {
            do {
                $status = curl_multi_exec($multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($running) {
                // Block until activity (or 1s) to avoid a tight loop.
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $index => $ch) {
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);

            if ($error || $httpCode >= 400) {
                $raw = (string) ($metas[$index]['raw'] ?? '');
                $rawSnippet = $raw !== '' ? substr($raw, -1000) : '';
                error_log("[AI Debug] Request {$index} ({$modelSlugs[$index]}) FAILED - HTTP: {$httpCode}, Error: {$error}");
                if ($rawSnippet !== '') {
                    error_log("[AI Debug] Request {$index} raw (tail): {$rawSnippet}");
                }
                if (is_callable($onError)) {
                    $detail = (string) $error;
                    if ($detail === '' && $rawSnippet !== '') {
                        $detail = $rawSnippet;
                    }
                    $onError($index, (int) $httpCode, $detail);
                }

                continue;
            }

            $onComplete($index, (int) ($metas[$index]['tokens'] ?? 0), $responses[$index]);
            error_log("[AI Debug] Request {$index} ({$modelSlugs[$index]}) completed successfully");
        }

        curl_multi_close($multi);

        return $responses;
    }

    /**
     * Stream panelists in parallel using a pre-built messages payload (for attachments).
     *
     * @param  array<string>  $modelSlugs
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>|null  $plugins
     * @param  callable(int $index, string $chunk): void  $onChunk
     * @param  callable(int $index, int $tokens, string $content): void  $onComplete
     * @return array<int, string>
     */
    public function streamParallelMessages(
        array $modelSlugs,
        array $messages,
        ?array $plugins,
        callable $onChunk,
        callable $onComplete,
        ?callable $onError = null,
    ): array {
        $responses = [];

        $multi = curl_multi_init();
        $handles = [];
        $metas = [];
        $results = [];

        foreach ($modelSlugs as $index => $slug) {
            $provider = $this->resolveProvider($slug);
            $metas[$index] = [];
            $responses[$index] = '';

            if (! method_exists($provider, 'buildStreamHandleFromPayload')) {
                throw new AIProviderException("Provider does not support message payload streaming: {$slug}");
            }

            $payload = [
                'model' => $provider->getModelId(),
                'stream' => true,
                'stream_options' => ['include_usage' => true],
                'messages' => $messages,
            ];
            if (is_array($plugins) && $plugins !== []) {
                $payload['plugins'] = $plugins;
            }

            /** @var callable $builder */
            $builder = [$provider, 'buildStreamHandleFromPayload'];
            $ch = $builder(
                $payload,
                function (string $chunk) use ($index, $onChunk, &$responses): void {
                    $responses[$index] .= $chunk;
                    $onChunk($index, $chunk);
                },
                $metas[$index],
            );

            $handles[$index] = $ch;
            curl_multi_add_handle($multi, $ch);
        }

        $running = null;
        do {
            do {
                $status = curl_multi_exec($multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $index => $ch) {
            $error = (string) curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);

            $raw = (string) ($metas[$index]['raw'] ?? '');
            $rawSnippet = $raw !== '' ? substr($raw, -1500) : '';

            $detail = $error;
            if ($detail === '' && $rawSnippet !== '') {
                $detail = $rawSnippet;
            }

            $results[$index] = [
                'http' => $httpCode,
                'error' => $error,
                'detail' => $detail,
            ];
        }

        curl_multi_close($multi);

        $fallbackEngine = $this->openRouterPdfFallbackEngine();
        $shouldRetry = [];

        foreach ($modelSlugs as $index => $slug) {
            $httpCode = (int) ($results[$index]['http'] ?? 0);
            $error = (string) ($results[$index]['error'] ?? '');
            $detail = (string) ($results[$index]['detail'] ?? '');

            if ($error === '' && $httpCode < 400) {
                $onComplete($index, (int) ($metas[$index]['tokens'] ?? 0), $responses[$index]);

                continue;
            }

            if ($fallbackEngine !== null && $this->isOpenRouterPdfParseFailure($httpCode, $detail)) {
                $shouldRetry[] = $index;

                continue;
            }

            if (is_callable($onError)) {
                $onError($index, $httpCode, $detail);
            }
        }

        if ($shouldRetry === [] || $fallbackEngine === null) {
            return $responses;
        }

        $retryPlugins = $this->rewriteOpenRouterPdfEngine($plugins, $fallbackEngine);

        $retrySlugs = [];
        foreach ($shouldRetry as $origIndex) {
            $retrySlugs[$origIndex] = $modelSlugs[$origIndex];
            $responses[$origIndex] = '';
        }

        $retryMulti = curl_multi_init();
        $retryHandles = [];
        $retryMetas = [];
        $retryResults = [];

        foreach ($retrySlugs as $origIndex => $slug) {
            $provider = $this->resolveProvider($slug);
            $retryMetas[$origIndex] = [];

            if (! method_exists($provider, 'buildStreamHandleFromPayload')) {
                throw new AIProviderException("Provider does not support message payload streaming: {$slug}");
            }

            $payload = [
                'model' => $provider->getModelId(),
                'stream' => true,
                'stream_options' => ['include_usage' => true],
                'messages' => $messages,
            ];

            if (is_array($retryPlugins) && $retryPlugins !== []) {
                $payload['plugins'] = $retryPlugins;
            }

            /** @var callable $builder */
            $builder = [$provider, 'buildStreamHandleFromPayload'];
            $ch = $builder(
                $payload,
                function (string $chunk) use ($origIndex, $onChunk, &$responses): void {
                    $responses[$origIndex] .= $chunk;
                    $onChunk($origIndex, $chunk);
                },
                $retryMetas[$origIndex],
            );

            $retryHandles[$origIndex] = $ch;
            curl_multi_add_handle($retryMulti, $ch);
        }

        $running = null;
        do {
            do {
                $status = curl_multi_exec($retryMulti, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($running) {
                curl_multi_select($retryMulti, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($retryHandles as $origIndex => $ch) {
            $error = (string) curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_multi_remove_handle($retryMulti, $ch);
            curl_close($ch);

            $raw = (string) ($retryMetas[$origIndex]['raw'] ?? '');
            $rawSnippet = $raw !== '' ? substr($raw, -1500) : '';

            $detail = $error;
            if ($detail === '' && $rawSnippet !== '') {
                $detail = $rawSnippet;
            }

            $retryResults[$origIndex] = [
                'http' => $httpCode,
                'error' => $error,
                'detail' => $detail,
            ];
        }

        curl_multi_close($retryMulti);

        foreach ($shouldRetry as $origIndex) {
            $httpCode = (int) ($retryResults[$origIndex]['http'] ?? 0);
            $error = (string) ($retryResults[$origIndex]['error'] ?? '');
            $detail = (string) ($retryResults[$origIndex]['detail'] ?? '');

            if ($error === '' && $httpCode < 400) {
                $onComplete($origIndex, (int) ($retryMetas[$origIndex]['tokens'] ?? 0), $responses[$origIndex]);

                continue;
            }

            if (is_callable($onError)) {
                $onError($origIndex, $httpCode, $detail);
            }
        }

        return $responses;
    }

    /**
     * Stream a single model using a pre-built messages payload (for attachments).
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>|null  $plugins
     */
    public function streamSingleMessages(string $modelSlug, array $messages, ?array $plugins, callable $onChunk): string
    {
        $provider = $this->resolveProvider($modelSlug);
        $meta = [];
        $fullResponse = '';

        if (! method_exists($provider, 'buildStreamHandleFromPayload')) {
            throw new AIProviderException("Provider does not support message payload streaming: {$modelSlug}");
        }

        $payload = [
            'model' => $provider->getModelId(),
            'stream' => true,
            'stream_options' => ['include_usage' => true],
            'messages' => $messages,
        ];
        if (is_array($plugins) && $plugins !== []) {
            $payload['plugins'] = $plugins;
        }

        $attempt = function (array $payload, array &$meta) use ($provider, $onChunk, &$fullResponse): array {
            $fullResponse = '';
            $meta = [];

            /** @var callable $builder */
            $builder = [$provider, 'buildStreamHandleFromPayload'];
            $ch = $builder(
                $payload,
                function (string $chunk) use ($onChunk, &$fullResponse): void {
                    $fullResponse .= $chunk;
                    $onChunk($chunk);
                },
                $meta,
            );

            curl_exec($ch);

            $error = (string) curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $raw = (string) ($meta['raw'] ?? '');
            $rawSnippet = $raw !== '' ? substr($raw, -1500) : '';

            $detail = $error;
            if ($detail === '' && $rawSnippet !== '') {
                $detail = $rawSnippet;
            }

            return [$httpCode, $error, $detail];
        };

        [$httpCode, $error, $detail] = $attempt($payload, $meta);

        if ($error === '' && $httpCode < 400) {
            return $fullResponse;
        }

        $fallbackEngine = $this->openRouterPdfFallbackEngine();
        if ($fallbackEngine !== null && $this->isOpenRouterPdfParseFailure($httpCode, $detail)) {
            $retryPlugins = $this->rewriteOpenRouterPdfEngine($plugins, $fallbackEngine);
            $retryPayload = $payload;
            if (is_array($retryPlugins) && $retryPlugins !== []) {
                $retryPayload['plugins'] = $retryPlugins;
            }

            $retryMeta = [];
            [$retryHttp, $retryError, $retryDetail] = $attempt($retryPayload, $retryMeta);

            if ($retryError === '' && $retryHttp < 400) {
                return $fullResponse;
            }

            throw new AIProviderException("Stream error for referee '{$modelSlug}': HTTP {$retryHttp} - {$retryDetail}");
        }

        throw new AIProviderException("Stream error for referee '{$modelSlug}': HTTP {$httpCode} - {$detail}");
    }

    /**
     * Stream a single model (used for the referee) and return the full response.
     *
     * @param  callable(string $chunk): void  $onChunk
     */
    public function streamSingle(string $modelSlug, string $prompt, callable $onChunk): string
    {
        $provider = $this->resolveProvider($modelSlug);
        $meta = [];
        $fullResponse = '';

        $ch = $provider->buildStreamHandle(
            $prompt,
            function (string $chunk) use ($onChunk, &$fullResponse): void {
                $fullResponse .= $chunk;
                $onChunk($chunk);
            },
            $meta,
        );

        curl_exec($ch);

        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error || $httpCode >= 400) {
            $raw = (string) ($meta['raw'] ?? '');
            $rawSnippet = $raw !== '' ? substr($raw, -1500) : '';
            $detail = (string) $error;
            if ($detail === '' && $rawSnippet !== '') {
                $detail = $rawSnippet;
            }

            throw new AIProviderException("Stream error for referee '{$modelSlug}': HTTP {$httpCode} - {$detail}");
        }

        return $fullResponse;
    }

    /**
     * Return structured list of all configured models.
     *
     * @return array<int, array{id: string, name: string, provider: string, model_id: string}>
     */
    public function listModels(): array
    {
        return collect(config('ai.models', []))
            ->map(fn (array $cfg, string $slug) => [
                'id' => $slug,
                'name' => $cfg['name'],
                'provider' => $cfg['provider'],
                'model_id' => $cfg['model_id'],
            ])
            ->values()
            ->all();
    }

    /** @throws InvalidModelException */
    public function resolveProvider(string $slug): AIProvider
    {
        $legacyMap = [
            'llama3-8b-instruct' => 'meta-llama/llama-3-8b-instruct',
            'mistral-7b-instruct-v0.1' => 'mistralai/mistral-7b-instruct-v0.1',
            'qwen-2.5-7b-instruct' => 'qwen/qwen-2.5-7b-instruct',
            'mixtral-8x7b-instruct' => 'mistralai/mixtral-8x7b-instruct',
            'deepseek-chat' => 'deepseek/deepseek-chat',
            'deepseek-r1' => 'deepseek/deepseek-r1',
            'gemma3-12b-it' => 'google/gemma-3-12b-it',
        ];

        if (isset($legacyMap[$slug])) {
            $slug = $legacyMap[$slug];
        }

        $config = config("ai.models.{$slug}");

        if (! $config) {
            // Fallback: allow passing an OpenRouter model id directly (e.g. "deepseek/deepseek-r1").
            // This prevents hard failures when config cache is stale or when users select
            // models by id instead of internal slugs.
            if (str_contains($slug, '/')) {
                $apiKey = (string) config('ai.api_keys.openrouter', '');
                if ($apiKey === '') {
                    throw new InvalidModelException("Unknown model slug: {$slug}");
                }

                return new OpenRouterProvider($apiKey, $slug, $slug);
            }

            throw new InvalidModelException("Unknown model slug: {$slug}");
        }

        $apiKey = config("ai.models.{$slug}.api_key")
            ?? config("ai.api_keys.{$config['provider']}");

        return match ($config['provider']) {
            'anthropic' => new AnthropicProvider($apiKey, $config['model_id'], $config['name']),
            'openai' => new OpenAIProvider($apiKey, $config['model_id'], $config['name']),
            'google' => new GoogleProvider($apiKey, $config['model_id'], $config['name']),
            'moonshot' => new MoonshotProvider($apiKey, $config['model_id'], $config['name']),
            'openrouter' => new OpenRouterProvider($apiKey, $config['model_id'], $config['name']),
            default => throw new InvalidModelException("Unsupported provider: {$config['provider']}"),
        };
    }
}
