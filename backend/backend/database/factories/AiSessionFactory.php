<?php

namespace Database\Factories;

use App\Models\AiSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiSession>
 */
class AiSessionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(5),
            'model_set' => [
                'panelists' => ['kimi-1', 'kimi-2', 'kimi-3'],
            ],
            'referee_model' => 'kimi-4',
        ];
    }
}
