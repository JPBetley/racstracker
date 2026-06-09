<?php

namespace App\Actions\Members;

use App\Enums\MemberPosition;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateMember
{
    /**
     * Update a roster member, enforcing position caps when the position changes.
     *
     * @throws ValidationException
     */
    public function handle(Member $member, string $name, MemberPosition $position): Member
    {
        return DB::transaction(function () use ($member, $name, $position) {
            if ($member->position !== $position) {
                $this->guardPositionCap($member, $position);
            }

            $member->update([
                'name' => $name,
                'position' => $position,
            ]);

            return $member;
        });
    }

    /**
     * Ensure the team has not reached the cap for the given position,
     * excluding the member being updated.
     *
     * @throws ValidationException
     */
    private function guardPositionCap(Member $member, MemberPosition $position): void
    {
        $cap = $position->maxPerTeam();

        if ($cap === null) {
            return;
        }

        $count = $member->team->roster()
            ->where('position', $position->value)
            ->whereKeyNot($member->getKey())
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
