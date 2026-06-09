<?php

namespace App\Actions\Members;

use App\Models\Member;

class DeleteMember
{
    /**
     * Remove a member from the team's roster.
     */
    public function handle(Member $member): void
    {
        $member->delete();
    }
}
