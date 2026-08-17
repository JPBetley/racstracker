<?php

namespace App\Actions\Members;

use App\Models\Member;
use App\Models\MemberAlias;

class CreateMemberAlias
{
    /**
     * Record another name a member is known by.
     */
    public function handle(Member $member, string $name): MemberAlias
    {
        return $member->aliases()->create(['name' => trim($name)]);
    }
}
