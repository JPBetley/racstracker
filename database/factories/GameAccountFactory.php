<?php

namespace Database\Factories;

use App\Models\GameAccount;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameAccount>
 */
class GameAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'device_id' => bin2hex(random_bytes(16)).'_n3d',
            'game_uid' => (string) fake()->numerify('#############'),
            'zone' => 'APS'.fake()->numberBetween(1, 999),
            'login_key' => fake()->sha256(),
            'access_token' => fake()->sha256(),
            'access_token_issued_at' => now(),
            'refresh_token' => fake()->sha256(),
            'app_version' => '1.0.364',
            'version_code' => '1892',
        ];
    }

    /**
     * An account whose access token is past the refresh age.
     */
    public function staleAccessToken(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_token_issued_at' => now()->subDays(GameAccount::ACCESS_TOKEN_MAX_AGE_DAYS + 1),
        ]);
    }
}
