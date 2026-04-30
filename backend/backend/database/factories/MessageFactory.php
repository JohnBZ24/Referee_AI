<?php

namespace Database\Factories;

use App\Models\AiSession;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'session_id' => AiSession::factory(),
            'role' => fake()->randomElement(['user', 'panelist', 'referee']),
            'model_name' => null,
            'panel_position' => null,
            'content' => fake()->paragraph(),
            'status' => 'complete',
            'tokens_used' => fake()->numberBetween(50, 500),
        ];
    }

    public function user(): static
    {
        return $this->state([
            'role' => 'user',
            'model_name' => null,
            'panel_position' => null,
            'tokens_used' => null,
        ]);
    }

    public function panelist(int $position = 1, string $modelSlug = 'claude-3-5-sonnet'): static
    {
        return $this->state([
            'role' => 'panelist',
            'model_name' => $modelSlug,
            'panel_position' => $position,
        ]);
    }

    public function referee(): static
    {
        return $this->state([
            'role' => 'referee',
            'model_name' => 'claude-3-5-sonnet',
            'panel_position' => null,
        ]);
    }
}
