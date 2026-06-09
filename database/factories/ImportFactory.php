<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Models\Import;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
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
            'user_id' => User::factory(),
            'type' => ImportType::Roster,
            'status' => ImportStatus::Pending,
            'payload' => ['members' => [
                ['name' => fake()->unique()->userName(), 'position' => 'R3'],
            ]],
            'results' => null,
        ];
    }

    /**
     * Indicate that the import is processing.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ImportStatus::Processing,
            'started_at' => Date::now(),
        ]);
    }

    /**
     * Indicate that the import has completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ImportStatus::Completed,
            'started_at' => Date::now(),
            'completed_at' => Date::now(),
            'results' => ['total' => 1, 'created' => 1, 'skipped' => []],
        ]);
    }

    /**
     * Indicate that the import has failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ImportStatus::Failed,
            'started_at' => Date::now(),
            'failed_at' => Date::now(),
            'error' => 'Something went wrong.',
        ]);
    }
}
