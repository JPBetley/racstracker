<?php

namespace App\Actions\Members;

use App\Models\MemberAlias;
use Illuminate\Validation\ValidationException;

class UpdateMemberAlias
{
    /**
     * Correct a name a member is known by.
     *
     * @throws ValidationException
     */
    public function handle(MemberAlias $alias, string $name): MemberAlias
    {
        $this->guardCurrentName($alias);

        $alias->update(['name' => trim($name)]);

        return $alias;
    }

    /**
     * Refuse to touch the name the member currently goes by.
     *
     * The roster sync records the current name as an alias like any other, so this
     * row is real and editable in principle. Renaming it here would be undone by
     * the next sync and would disagree with the roster, so the member form stays
     * the one place a current name changes.
     *
     * @throws ValidationException
     */
    private function guardCurrentName(MemberAlias $alias): void
    {
        if ($alias->name === $alias->member->name) {
            throw ValidationException::withMessages([
                'alias' => __("A member's current name is changed on their profile."),
            ]);
        }
    }
}
