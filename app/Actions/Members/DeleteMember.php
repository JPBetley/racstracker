<?php

namespace App\Actions\Members;

use App\Models\Member;

class DeleteMember
{
    /**
     * Remove a member from the team's roster permanently.
     *
     * Deleting by hand is deliberate and final, so it discards the row and its
     * scores outright. The alliance roster sync instead deactivates members who
     * have left, keeping their history intact.
     */
    public function handle(Member $member): void
    {
        $member->delete();
    }
}
