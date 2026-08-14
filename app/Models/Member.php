<?php

namespace App\Models;

use App\Enums\MemberPosition;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['team_id', 'uid', 'name', 'position', 'is_active'])]
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    /**
     * Scope the query to members still in the alliance.
     *
     * Members who leave are deactivated rather than deleted, so their scores stay
     * queryable in historical views. Anything showing the current roster should
     * apply this scope explicitly.
     *
     * @param  Builder<Member>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope the query to members who have left the alliance.
     *
     * @param  Builder<Member>  $query
     */
    #[Scope]
    protected function inactive(Builder $query): void
    {
        $query->where('is_active', false);
    }

    /**
     * Get the team this member belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get every name this member has been known by.
     *
     * @return HasMany<MemberAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(MemberAlias::class);
    }

    /**
     * Record a name this member is known by, ignoring one already stored.
     *
     * When the aliases relation is loaded, a known name costs no query at all,
     * which keeps a roster sync over an unchanged alliance from issuing one per
     * member. The loaded relation is kept in step so repeat calls stay accurate.
     */
    public function recordAlias(?string $name): void
    {
        $name = trim((string) $name);

        if ($name === '') {
            return;
        }

        $loaded = $this->relationLoaded('aliases');

        if ($loaded && $this->aliases->contains('name', $name)) {
            return;
        }

        $alias = $this->aliases()->firstOrCreate(['name' => $name]);

        if ($loaded) {
            $this->aliases->push($alias);
        }
    }

    /**
     * Get the daily scores recorded for this member.
     *
     * @return HasMany<Score, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => MemberPosition::class,
            'is_active' => 'boolean',
        ];
    }
}
