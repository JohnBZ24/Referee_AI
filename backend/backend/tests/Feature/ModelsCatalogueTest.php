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
