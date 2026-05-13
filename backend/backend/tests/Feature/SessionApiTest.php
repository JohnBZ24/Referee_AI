<?php

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

it('creates a session with the authenticated user when available', function (): void {
    $user = User::factory()->create();

    $res = $this->actingAs($user)->postJson('/api/v1/sessions', []);

    $res->assertCreated();
    expect($res->json('data.id'))->not->toBeNull();
});
