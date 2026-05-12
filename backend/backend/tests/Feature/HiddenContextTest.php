<?php

use App\Models\AiSession;
use App\Models\Message;
use App\Services\AI\AIService;
use Tests\Support\FakeAIService;

it('keeps referenced last message hidden from stored prompt but includes it in AI prompt', function () {
    $target = AiSession::factory()->create();
    $other = AiSession::factory()->create(['title' => 'Other Chat']);

    Message::factory()->for($other, 'session')->user()->create([
        'content' => 'This is the last message from the other chat.',
        'status' => 'complete',
    ]);

    Message::factory()->for($other, 'session')->referee()->create([
        'content' => "Winner: kimi-2\nSummary: This is the verdict from the other chat.",
        'status' => 'complete',
    ]);

    $spy = new FakeAIService;

    app()->instance(AIService::class, $spy);

    $payload = [
        'prompt' => 'My visible question',
        'context_json' => json_encode([
            'references' => [
                ['session_id' => (string) $other->id],
            ],
        ]),
    ];

    $res = $this->postJson('/api/v1/sessions/'.$target->id.'/prompt', $payload);
    $res->assertOk();
    $res->streamedContent();

    expect(Message::query()
        ->where('session_id', $target->id)
        ->where('role', 'user')
        ->latest('id')
        ->value('content'))
        ->toBe('My visible question');

    expect($spy->lastParallelPrompt)
        ->toContain('My visible question')
        ->toContain('Referenced verdict')
        ->toContain('Winner: kimi-2')
        ->toContain('This is the verdict from the other chat.');

    expect($spy->lastParallelPrompt)
        ->toContain('Full referee verdict');
});
