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
            'date' => $this->weekday(),
            'points' => fake()->numberBetween(0, 5_000_000),
        ];
    }

    /**
     * Record the score against a specific date.
     */
    public function onDate(CarbonInterface $date): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date->toDateString(),
        ]);
    }

    /**
     * Generate a date that falls on a scoring weekday (Monday through Saturday).
     */
    private function weekday(): string
    {
        $date = Carbon::instance(fake()->dateTimeBetween('-8 weeks', 'now'));

        if ($date->dayOfWeekIso === 7) {
            $date = $date->subDay();
        }

        return $date->toDateString();
    }
}
