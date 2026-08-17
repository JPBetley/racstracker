<?php

namespace App\Actions\Conductors;

use App\Models\ConductorAssignment;
use App\Models\Member;
use Carbon\CarbonInterface;

class UpdateConductorAssignment
{
    /**
     * Change who conducted on a recorded day, or move the record to another day.
     */
    public function handle(ConductorAssignment $assignment, Member $member, CarbonInterface $assignedOn): ConductorAssignment
    {
        $assignment->update([
            'member_id' => $member->id,
            'assigned_on' => $assignedOn->toDateString(),
        ]);

        return $assignment;
    }
}
