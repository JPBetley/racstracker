<?php

namespace App\Actions\Conductors;

use App\Models\ConductorAssignment;
use App\Models\Member;
use App\Models\Team;
use Carbon\CarbonInterface;

class CreateConductorAssignment
{
    /**
     * Record the member serving as train conductor on the given day.
     *
     * A team has one conductor per day, enforced by a unique index on
     * [team_id, assigned_on] and validated before this action is reached.
     *
     * `$isMvp` marks this conductor as having earned MVP for the week. MVP is a
     * weekly award, so it lands on the one day that earned it rather than being
     * tracked as a separate record.
     */
    public function handle(Team $team, Member $member, CarbonInterface $assignedOn, bool $isMvp = false): ConductorAssignment
    {
        return $team->conductorAssignments()->create([
            'member_id' => $member->id,
            'assigned_on' => $assignedOn->toDateString(),
            'is_mvp' => $isMvp,
        ]);
    }
}
