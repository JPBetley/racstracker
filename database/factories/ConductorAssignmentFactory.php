<?php

namespace Database\Factories;

use App\Models\ConductorAssignment;
use App\Models\Member;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ConductorAssignment>
 */
class ConductorAssignmentFactory extends Factory
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
            'member_id' => fn (array $attributes) => Member::factory()->create([
                'team_id' => $attributes['team_id'],
            ]),
            'assigned_on' => $this->recentDay(),
        ];
    }

    /**
     * Record the assignment against a specific day.
     */
    public function on(CarbonInterface $date): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_on' => $date->toDateString(),
        ]);
    }

    /**
     * Generate a day within the last year, never repeating one already used.
     *
     * Assignments are unique per team per day, so colliding dates would make
     * any factory creating more than a handful of rows fail at random.
     */
    private function recentDay(): string
    {
        return Carbon::today()
            ->subDays(fake()->unique()->numberBetween(0, 365))
            ->toDateString();
    }
}
