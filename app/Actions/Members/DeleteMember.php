<?php

namespace App\Actions\Members;

use App\Models\Member;

class DeleteMember
{
    /**
     * Deactivate a member so they drop off the active roster.
     *
     * Historical scores and conductor assignments are preserved exactly like an
     * alliance sync departure — the only difference is that this is user-initiated.
     */
    public function handle(Member $member): void
    {
        $member->update(['is_active' => false]);
    }
}
