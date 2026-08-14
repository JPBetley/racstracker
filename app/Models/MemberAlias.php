<?php

namespace App\Models;

use Database\Factories\MemberAliasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A name a member has been known by.
 *
 * Players rename themselves in game, so the current name is a poor identity. Every
 * name a member has ever carried is kept here, which lets screenshot imports and
 * fuzzy matching still resolve to the right member — and keeps week-over-week VS
 * scores attached to one person across a rename.
 */
#[Fillable(['member_id', 'name'])]
class MemberAlias extends Model
{
    /** @use HasFactory<MemberAliasFactory> */
    use HasFactory;

    /**
     * Get the member this name belongs to.
     *
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
