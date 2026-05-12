<?php

namespace App\Services\WebSearch;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TavilyWebSearchService
{
    /**
     * @return array{answer: string, results: array<int, array{title: string, url: string, snippet: string}>}
     */
    public function search(string $query, int $maxResults = 5): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['answer' => '', 'results' => []];
        }

        $token = (string) config('referee_ai.tavily_api_key', '');
        if ($token === '') {
            return ['answer' => '', 'results' => []];
        }

        $maxResults = max(1, min(10, $maxResults));

        $cacheKey = 'websearch:tavily:'.sha1(strtolower($query).':'.$maxResults);

        try {
            return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($token, $query, $maxResults): array {
                $resp = Http::timeout(12)
                    ->retry(2, 250)
                    ->asJson()
                    ->acceptJson()
                    ->withToken($token)
                    ->withHeaders([
                        'User-Agent' => 'RefereeAI/1.0',
                    ])
                    ->post('https://api.tavily.com/search', [
                        'query' => $query,
                        'max_results' => $maxResults,
                        // Keep it safe / consistent.
                        'include_answer' => true,
                        'include_images' => false,
                        'include_raw_content' => false,
                    ])
                    ->throw();

                /** @var array<string, mixed> $json */
                $json = $resp->json() ?? [];
                $answer = trim((string) ($json['answer'] ?? ''));
                $results = $json['results'] ?? null;

                if (! is_array($results)) {
                    return ['answer' => $answer, 'results' => []];
                }

                $out = [];
                foreach (array_slice($results, 0, $maxResults) as $r) {
                    if (! is_array($r)) {
                        continue;
                    }

                    $title = trim((string) ($r['title'] ?? ''));
                    $url = trim((string) ($r['url'] ?? ''));
                    $snippet = trim((string) ($r['content'] ?? ($r['snippet'] ?? '')));

                    if ($url === '' || $title === '') {
                        continue;
                    }

                    $out[] = [
                        'title' => $title,
                        'url' => $url,
                        'snippet' => $snippet,
                    ];
                }

                return ['answer' => $answer, 'results' => $out];
            });
        } catch (RequestException $e) {
            $resp = $e->response;
            $body = $resp ? (string) $resp->body() : '';
            if (strlen($body) > 600) {
                $body = substr($body, 0, 600).'...';
            }

            Log::warning('tavily_web_search_failed', [
                'status' => $resp?->status(),
                'error' => $e->getMessage(),
                'body' => $body,
            ]);

            return ['answer' => '', 'results' => []];
        } catch (\Throwable $e) {
            Log::warning('tavily_web_search_failed', [
                'error' => $e->getMessage(),
            ]);

            return ['answer' => '', 'results' => []];
        }
    }
}
