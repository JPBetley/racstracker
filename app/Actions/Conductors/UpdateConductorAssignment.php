<?php

namespace App\Actions\Conductors;

use App\Models\ConductorAssignment;
use App\Models\Member;
use Carbon\CarbonInterface;

class UpdateConductorAssignment
{
    /**
     * Change who conducted on a recorded day, or move the record to another day.
     *
     * `$isMvp` is always written, so clearing the box in the form clears the badge.
     */
    public function handle(ConductorAssignment $assignment, Member $member, CarbonInterface $assignedOn, bool $isMvp = false): ConductorAssignment
    {
        $assignment->update([
            'member_id' => $member->id,
            'assigned_on' => $assignedOn->toDateString(),
            'is_mvp' => $isMvp,
        ]);

        return $assignment;
    }
}
