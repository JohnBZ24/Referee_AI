<?php

use App\Models\AiSession;
use App\Models\Message;
use App\Services\AI\AIService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAIService;

it('injects brave web sources into the AI prompt when enabled', function () {
    Config::set('referee_ai.web_search_provider', 'brave');
    Config::set('referee_ai.brave_search_api_key', 'test-key');
    Config::set('referee_ai.web_search_default_mode', 'on');
    Config::set('referee_ai.web_search_max_results', 2);

    Http::fake([
        'api.search.brave.com/*' => Http::response([
            'web' => [
                'results' => [
                    [
                        'title' => 'Example Gas Prices',
                        'url' => 'https://example.com/gas',
                        'description' => 'Example snippet',
                    ],
                ],
            ],
        ], 200),
    ]);

    $session = AiSession::factory()->create();

    $spy = new FakeAIService;

    app()->instance(AIService::class, $spy);

    $res = $this->postJson('/api/v1/sessions/'.$session->id.'/prompt', [
        'prompt' => 'search for me the price of gas',
        'web_search_mode' => 'on',
    ]);

    $res->assertOk();
    $res->streamedContent();

    expect($spy->lastParallelPrompt)
        ->toContain('Web sources (Brave Search)')
        ->toContain('Example Gas Prices')
        ->toContain('https://example.com/gas');

    expect(Message::query()->where('session_id', $session->id)->where('role', 'user')->latest('id')->value('content'))
        ->toBe('search for me the price of gas');
});
