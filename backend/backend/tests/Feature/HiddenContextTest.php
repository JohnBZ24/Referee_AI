<?php

use App\Models\AiSession;
use App\Models\Message;
use App\Services\AI\AIService;

it('keeps referenced last message hidden from stored prompt but includes it in AI prompt', function () {
    $target = AiSession::factory()->create();
    $other = AiSession::factory()->create(['title' => 'Other Chat']);

    $refMsg = Message::factory()->for($other, 'session')->user()->create([
        'content' => 'This is the last message from the other chat.',
        'status' => 'complete',
    ]);

    $spy = new class extends AIService
    {
        public string $lastParallelPrompt = '';

        public function streamParallel(array $modelSlugs, string $prompt, callable $onChunk, callable $onComplete, ?callable $onError = null): array
        {
            $this->lastParallelPrompt = $prompt;

            $responses = [];
            foreach ($modelSlugs as $i => $slug) {
                $onChunk($i, 'ok');
                $onComplete($i, 0, 'ok');
                $responses[$i] = 'ok';
            }

            return $responses;
        }

        public function streamSingle(string $modelSlug, string $prompt, callable $onChunk): string
        {
            $onChunk('ok');

            return 'ok';
        }
    };

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
        ->toContain('This is the last message from the other chat.');
});
