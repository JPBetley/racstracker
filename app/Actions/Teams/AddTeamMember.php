<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AddTeamMember
{
    /**
     * Add an already registered user to the team.
     *
     * Adding somebody who is already on the team is a no-op rather than an error,
     * so a double click or a stale search result cannot duplicate the membership
     * or quietly change the role they already hold.
     */
    public function handle(Team $team, User $user, TeamRole $role): Membership
    {
        return DB::transaction(fn () => $team->memberships()->firstOrCreate(
            ['user_id' => $user->id],
            ['role' => $role],
        ));
    }
}
