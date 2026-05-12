<?php

namespace App\Services\WebSearch;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BraveWebSearchService
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

        $token = (string) config('referee_ai.brave_search_api_key', '');
        if ($token === '') {
            return [];
        }

        $maxResults = max(1, min(10, $maxResults));

        $cacheKey = 'websearch:brave:'.sha1(strtolower($query).':'.$maxResults);

        try {
            return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($token, $query, $maxResults): array {
                $resp = Http::timeout(12)
                    ->retry(2, 250)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'X-Subscription-Token' => $token,
                    ])
                    ->get('https://api.search.brave.com/res/v1/web/search', [
                        'q' => $query,
                        'count' => $maxResults,
                        // Keep it simple / safe: no personalization.
                        'safesearch' => 'moderate',
                    ])
                    ->throw();

                /** @var array<string, mixed> $json */
                $json = $resp->json() ?? [];
                $results = data_get($json, 'web.results');

                if (! is_array($results)) {
                    return [];
                }

                $out = [];
                foreach (array_slice($results, 0, $maxResults) as $r) {
                    if (! is_array($r)) {
                        continue;
                    }

                    $title = trim((string) ($r['title'] ?? ''));
                    $url = trim((string) ($r['url'] ?? ''));
                    $snippet = trim((string) ($r['description'] ?? ($r['snippet'] ?? '')));

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
        } catch (\Throwable $e) {
            Log::warning('brave_web_search_failed', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
