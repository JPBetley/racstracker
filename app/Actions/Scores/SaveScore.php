<?php

namespace App\Actions\Scores;

use App\Models\Member;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveScore
{
    /**
     * Record (or clear) a member's score for a single scoring day.
     *
     * A null point value removes the score, letting users clear a cell.
     *
     * @throws ValidationException
     */
    public function handle(Member $member, CarbonInterface $date, ?int $points): void
    {
        $this->guardScoringDay($date);

        $date = $date->startOfDay();

        DB::transaction(function () use ($member, $date, $points) {
            if ($points === null) {
                $member->scores()->whereDate('date', $date)->delete();

                return;
            }

            $member->scores()->updateOrCreate(
                ['date' => $date],
                ['points' => $points],
            );
        });
    }

    /**
     * Ensure the score falls on a scoring day (Monday through Saturday).
     *
     * @throws ValidationException
     */
    private function guardScoringDay(CarbonInterface $date): void
    {
        if ($date->dayOfWeekIso === 7) {
            throw ValidationException::withMessages([
                'points' => __('Scores cannot be recorded on a Sunday.'),
            ]);
        }
    }
}
