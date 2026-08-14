<?php

namespace Database\Factories;

use App\Enums\MemberPosition;
use App\Models\Member;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
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
            'name' => fake()->unique()->userName(),
            'position' => MemberPosition::R3,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the member has left the alliance.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the member holds the R5 position.
     */
    public function r5(): static
    {
        return $this->state(fn (array $attributes) => [
            'position' => MemberPosition::R5,
        ]);
    }

    /**
     * Indicate that the member holds the R4 position.
     */
    public function r4(): static
    {
        return $this->state(fn (array $attributes) => [
            'position' => MemberPosition::R4,
        ]);
    }
}
