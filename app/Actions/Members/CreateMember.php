<?php

namespace App\Actions\Members;

use App\Enums\MemberPosition;
use App\Models\Member;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateMember
{
    /**
     * Add a new member to the team's roster, enforcing position caps.
     *
     * @throws ValidationException
     */
    public function handle(Team $team, string $name, MemberPosition $position): Member
    {
        return DB::transaction(function () use ($team, $name, $position) {
            $this->guardPositionCap($team, $position);

            return $team->roster()->create([
                'name' => $name,
                'position' => $position,
            ]);
        });
    }

    /**
     * Ensure the team has not reached the cap for the given position.
     *
     * @throws ValidationException
     */
    private function guardPositionCap(Team $team, MemberPosition $position): void
    {
        $cap = $position->maxPerTeam();

        if ($cap === null) {
            return;
        }

        $count = $team->roster()
            ->where('position', $position->value)
            ->lockForUpdate()
            ->count();

        if ($count >= $cap) {
            throw ValidationException::withMessages([
                'position' => __('Only :cap :position members are allowed.', [
                    'cap' => $cap,
                    'position' => $position->value,
                ]),
            ]);
        }
    }
}
