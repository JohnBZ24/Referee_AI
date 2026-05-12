<?php

use App\Models\AiSession;
use App\Models\Message;
use App\Services\AI\AIService;
use Tests\Support\FakeAIService;

it('includes prior messages from the same session as conversation context', function () {
    $session = AiSession::factory()->create();

    Message::factory()->for($session, 'session')->user()->create([
        'content' => 'Earlier question from the user.',
        'status' => 'complete',
    ]);

    Message::factory()->for($session, 'session')->referee()->create([
        'content' => "Winner: kimi-1\nReasoning: Earlier assistant response.",
        'status' => 'complete',
    ]);

    $spy = new FakeAIService;

    app()->instance(AIService::class, $spy);

    $res = $this->postJson('/api/v1/sessions/'.$session->id.'/prompt', [
        'prompt' => 'Follow-up question.',
        'context_mode' => 'on',
        'web_search_mode' => 'off',
    ]);

    $res->assertOk();
    $res->streamedContent();

    expect($spy->lastParallelPrompt)
        ->toContain('Conversation so far')
        ->toContain('Earlier question from the user.')
        ->toContain('Earlier assistant response.')
        ->toContain('Follow-up question.');
});
