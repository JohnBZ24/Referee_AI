<?php

namespace App\Services\WebSearch;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SerperWebSearchService
{
    /**
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    public function search(string $query, int $maxResults = 5): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $token = trim((string) config('referee_ai.serper_api_key', ''));
        if ($token === '') {
            return [];
        }

        $maxResults = max(1, min(10, $maxResults));
        $cacheKey = 'websearch:serper:'.sha1(strtolower($query).':'.$maxResults);

        try {
            return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($token, $query, $maxResults): array {
                $resp = Http::timeout(12)
                    ->retry(2, 250)
                    ->asJson()
                    ->acceptJson()
                    ->withHeaders([
                        'X-API-KEY' => $token,
                        'User-Agent' => 'RefereeAI/1.0',
                    ])
                    ->post('https://google.serper.dev/search', [
                        'q' => $query,
                        'num' => $maxResults,
                    ])
                    ->throw();

                /** @var array<string, mixed> $json */
                $json = $resp->json() ?? [];
                $organic = $json['organic'] ?? null;
                if (! is_array($organic)) {
                    return [];
                }

                $out = [];
                foreach (array_slice($organic, 0, $maxResults) as $r) {
                    if (! is_array($r)) {
                        continue;
                    }

                    $title = trim((string) ($r['title'] ?? ''));
                    $url = trim((string) ($r['link'] ?? ''));
                    $snippet = trim((string) ($r['snippet'] ?? ''));

                    if ($url === '' || $title === '') {
                        continue;
                    }

                    $out[] = [
                        'title' => $title,
                        'url' => $url,
                        'snippet' => $snippet,
                    ];
                }

                return $out;
            });
        } catch (RequestException $e) {
            $resp = $e->response;
            $body = $resp ? (string) $resp->body() : '';
            if (strlen($body) > 600) {
                $body = substr($body, 0, 600).'...';
            }

            Log::warning('serper_web_search_failed', [
                'status' => $resp?->status(),
                'error' => $e->getMessage(),
                'body' => $body,
            ]);

            return [];
        } catch (\Throwable $e) {
            Log::warning('serper_web_search_failed', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
