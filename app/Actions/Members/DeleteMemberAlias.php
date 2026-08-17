<?php

namespace App\Actions\Members;

use App\Models\MemberAlias;
use Illuminate\Validation\ValidationException;

class DeleteMemberAlias
{
    /**
     * Forget a name a member is known by.
     *
     * @throws ValidationException
     */
    public function handle(MemberAlias $alias): void
    {
        if ($alias->name === $alias->member->name) {
            throw ValidationException::withMessages([
                'alias' => __("A member's current name cannot be forgotten."),
            ]);
        }

        $alias->delete();
    }
}
