<?php

use App\Models\AiSession;
use App\Models\User;

it('creates a session without authentication', function (): void {
    $res = $this->postJson('/api/v1/sessions', []);

    $res->assertCreated();
    $res->assertJsonStructure([
        'data' => [
            'id',
            'title',
            'model_set',
            'referee_model',
            'created_at',
            'updated_at',
        ],
    ]);
});

it('does not expose mistral models on sessions', function (): void {
    // Create a session that references mistral directly (legacy / old data)
    // and ensure the API maps it to a stable alternative when returning JSON.
    $user = User::factory()->create();

    $session = AiSession::create([
        'user_id' => $user->id,
        'title' => 'Legacy Session',
        'model_set' => [
            'panelists' => [
                'mistralai/mistral-7b-instruct-v0.1',
                'mistralai/mixtral-8x7b-instruct',
                'qwen/qwen-2.5-7b-instruct',
            ],
        ],
        'referee_model' => 'mistralai/mixtral-8x7b-instruct',
    ]);

    $res = $this->getJson('/api/v1/sessions/'.$session->id);
    $res->assertOk();

    $panelists = (array) $res->json('data.model_set.panelists');
    $referee = (string) $res->json('data.referee_model');
    expect($panelists)->each(fn ($id) => $id->not->toStartWith('mistralai/'));
    expect($referee)->not->toStartWith('mistralai/');
});

it('creates a session with the authenticated user when available', function (): void {
    $user = User::factory()->create();

    $res = $this->actingAs($user)->postJson('/api/v1/sessions', []);

    $res->assertCreated();
    expect($res->json('data.id'))->not->toBeNull();
});
