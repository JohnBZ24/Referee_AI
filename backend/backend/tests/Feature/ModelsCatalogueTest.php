<?php

it('does not include mistral models in the public catalogue', function (): void {
    $res = $this->getJson('/api/v1/models');

    $res->assertOk();

    $models = $res->json();
    expect($models)->toBeArray();

    $ids = array_map(fn ($m) => (string) ($m['id'] ?? ''), $models);
    foreach ($ids as $id) {
        expect($id)->not->toStartWith('mistralai/');
    }
});

it('includes a few stable default models', function (): void {
    $res = $this->getJson('/api/v1/models');
    $res->assertOk();

    $ids = array_map(fn ($m) => (string) ($m['id'] ?? ''), (array) $res->json());

    expect($ids)->toContain('meta-llama/llama-3-8b-instruct');
    expect($ids)->toContain('qwen/qwen-2.5-7b-instruct');
    expect($ids)->toContain('deepseek/deepseek-chat');
    expect($ids)->toContain('openai/gpt-4o-mini');
});
