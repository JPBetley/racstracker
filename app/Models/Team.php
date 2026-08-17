<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueTeamSlugs;
use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'is_personal', 'alliance_id', 'vs_minimum', 'train_vs_requirement', 'train_desert_storm_requirement'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, SoftDeletes;

    /**
     * The model's default attribute values.
     *
     * The requirement columns default in the database too, but these mirror them
     * so a freshly created team reports "no requirement" without being refreshed.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'vs_minimum' => 0,
        'train_vs_requirement' => 0,
        'train_desert_storm_requirement' => false,
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = static::generateUniqueTeamSlug($team->name);
            }
        });

        static::updating(function (Team $team) {
            if ($team->isDirty('name')) {
                $team->slug = static::generateUniqueTeamSlug($team->name, $team->id);
            }
        });
    }

    /**
     * Get the team owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('role', TeamRole::Owner->value)
            ->first();
    }

    /**
     * Get all members of this team.
     *
     * @return BelongsToMany<Model, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this team.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get the alliance roster members tracked for this team.
     *
     * @return HasMany<Member, $this>
     */
    public function roster(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /**
     * Get the daily train conductor assignments recorded for this team.
     *
     * @return HasMany<ConductorAssignment, $this>
     */
    public function conductorAssignments(): HasMany
    {
        return $this->hasMany(ConductorAssignment::class);
    }

    /**
     * Get all invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get all imports started for this team.
     *
     * @return HasMany<Import, $this>
     */
    public function imports(): HasMany
    {
        return $this->hasMany(Import::class);
    }

    /**
     * Get the Last War alliance ID this team's roster is imported from.
     *
     * Falls back to the application-wide configured alliance so a single-team
     * install can be driven entirely from the environment.
     */
    public function allianceId(): ?string
    {
        return $this->alliance_id ?? config('services.lastwar.alliance_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'vs_minimum' => 'integer',
            'train_vs_requirement' => 'integer',
            'train_desert_storm_requirement' => 'boolean',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
