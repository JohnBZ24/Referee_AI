<?php

namespace Tests\Support;

use App\Services\AI\AIService;

class FakeAIService extends AIService
{
    public string $lastParallelPrompt = '';

    public function streamPanelists(
        array $modelSlugs,
        string $prompt,
        array $attachments,
        callable $onChunk,
        callable $onComplete,
        ?callable $onError = null,
    ): array {
        $this->lastParallelPrompt = $prompt;

        $responses = [];
        foreach ($modelSlugs as $i => $slug) {
            $onChunk($i, 'ok');
            $onComplete($i, 0, 'ok');
            $responses[$i] = 'ok';
        }

        return $responses;
    }

    public function streamSingle(string $modelSlug, string $prompt, array $attachments, callable $onChunk): string
    {
        $onChunk('ok');

        return "Winner: {$modelSlug}\nReasoning: ok";
    }

    public function complete(string $modelSlug, string $prompt, array $attachments = []): string
    {
        return '{"title":"Test Title"}';
    }
}
