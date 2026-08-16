<?php

namespace App\Actions\Scores;

use App\Models\Member;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SaveScore
{
    /**
     * Record (or clear) a member's full score for a single VS week.
     *
     * Any date within the week is accepted and normalised to that week's
     * Monday, so callers never have to align dates themselves. A null point
     * value removes the score, letting users clear a cell.
     */
    public function handle(Member $member, CarbonInterface $weekStart, ?int $points): void
    {
        $weekStart = $weekStart->startOfWeek(CarbonInterface::MONDAY)->startOfDay();

        DB::transaction(function () use ($member, $weekStart, $points) {
            if ($points === null) {
                $member->scores()->whereDate('week_start', $weekStart)->delete();

                return;
            }

            $member->scores()->updateOrCreate(
                ['week_start' => $weekStart],
                ['points' => $points],
            );
        });
    }
}
