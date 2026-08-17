<?php

namespace App\Actions\Conductors;

use App\Models\ConductorAssignment;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SaveWeekPlan
{
    public function __construct(
        private CreateConductorAssignment $createAssignment,
        private UpdateConductorAssignment $updateAssignment,
    ) {}

    /**
     * Record a whole week of train conductors in one go.
     *
     * A week runs Sunday through Saturday. The leading Sunday is the MVP day —
     * the award is earned by the VS week that just finished, so it lands on the
     * first day of the week being planned rather than on a record of its own.
     *
     * Days already on record are amended rather than inserted again: assignments
     * are unique per team per day, so re-planning a week the user has already
     * saved would otherwise collide with that index.
     *
     * @param  array<string, int|null>  $assignments  Member ID keyed by `Y-m-d`; a null day is left unassigned.
     * @return int The number of days written.
     */
    public function handle(Team $team, CarbonInterface $planStart, array $assignments): int
    {
        $mvpDay = $planStart->toDateString();

        return DB::transaction(function () use ($team, $assignments, $mvpDay): int {
            /**
             * Existing days are keyed through the model's date cast rather than
             * matched in SQL. The cast writes a full timestamp, so comparing a
             * bare `Y-m-d` in the database only lines up on some drivers.
             */
            $existing = $team->conductorAssignments()->get()
                ->keyBy(fn (ConductorAssignment $assignment): string => $assignment->assigned_on->toDateString());

            $written = 0;

            foreach ($assignments as $date => $memberId) {
                if ($memberId === null) {
                    continue;
                }

                $member = $team->roster()->findOrFail($memberId);
                $assignedOn = CarbonImmutable::parse($date);
                $isMvp = $date === $mvpDay;

                if ($assignment = $existing->get($date)) {
                    $this->updateAssignment->handle($assignment, $member, $assignedOn, $isMvp);
                } else {
                    $this->createAssignment->handle($team, $member, $assignedOn, $isMvp);
                }

                $written++;
            }

            return $written;
        });
    }
}
