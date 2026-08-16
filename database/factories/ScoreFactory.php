<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\Score;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Score>
 */
class ScoreFactory extends Factory
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
            'week_start' => $this->recentWeekStart(),
            'points' => fake()->numberBetween(0, 5_000_000),
        ];
    }

    /**
     * Record the score against the VS week containing the given date.
     */
    public function forWeek(CarbonInterface $date): static
    {
        return $this->state(fn (array $attributes) => [
            'week_start' => $date->startOfWeek(CarbonInterface::MONDAY)->toDateString(),
        ]);
    }

    /**
     * Generate the Monday of a VS week within the last couple of months.
     */
    private function recentWeekStart(): string
    {
        return Carbon::instance(fake()->dateTimeBetween('-8 weeks', 'now'))
            ->startOfWeek(CarbonInterface::MONDAY)
            ->toDateString();
    }
}
