<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\MemberAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberAlias>
 */
class MemberAliasFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'name' => fake()->unique()->userName(),
        ];
    }
}
