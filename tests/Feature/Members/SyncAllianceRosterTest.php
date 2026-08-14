<?php

use App\Actions\Members\CreateMember;
use App\Actions\Members\SyncAllianceRoster;
use App\Enums\MemberPosition;
use App\Imports\Ocr\RosterNameMatcher;
use App\Models\Member;
use App\Models\MemberAlias;
use App\Models\Score;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Build an API member row.
 *
 * @return array{uid: string, name: string, rank: int, power: int}
 */
function apiMember(string $uid, string $name, int $rank = 1, int $power = 1_000_000): array
{
    return ['uid' => $uid, 'name' => $name, 'rank' => $rank, 'power' => $power];
}

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->sync = app(SyncAllianceRoster::class);
});

it('adds members that are new to the roster', function () {
    $counts = $this->sync->handle($this->team, [
        apiMember('uid-1', 'Alpha'),
        apiMember('uid-2', 'Bravo'),
    ]);

    expect($counts['added'])->toBe(2)
        ->and($this->team->roster()->pluck('name')->all())->toEqualCanonicalizing(['Alpha', 'Bravo'])
        ->and($this->team->roster()->pluck('uid')->all())->toEqualCanonicalizing(['uid-1', 'uid-2']);
});

it('deactivates members that have left the alliance', function () {
    $staying = Member::factory()->for($this->team)->create(['uid' => 'uid-1', 'name' => 'Alpha']);
    $leaving = Member::factory()->for($this->team)->create(['uid' => 'uid-2', 'name' => 'Bravo']);

    $counts = $this->sync->handle($this->team, [apiMember('uid-1', 'Alpha')]);

    expect($counts['deactivated'])->toBe(1)
        ->and($leaving->fresh()->is_active)->toBeFalse()
        ->and($staying->fresh()->is_active)->toBeTrue()
        ->and($this->team->roster()->active()->count())->toBe(1)
        ->and($this->team->roster()->count())->toBe(2);
});

it('keeps a departed member and their scores queryable', function () {
    $leaving = Member::factory()->for($this->team)->create(['uid' => 'uid-2', 'name' => 'Bravo']);
    Score::factory()->for($leaving)->create(['points' => 999]);

    $this->sync->handle($this->team, [apiMember('uid-1', 'Alpha')]);

    expect($leaving->fresh())->not->toBeNull()
        ->and($leaving->fresh()->scores()->sum('points'))->toBe(999)
        ->and($this->team->roster()->inactive()->pluck('name')->all())->toBe(['Bravo']);
});

it('reactivates a returning member and keeps their score history', function () {
    $member = Member::factory()->for($this->team)->inactive()->create(['uid' => 'uid-1', 'name' => 'Alpha']);
    Score::factory()->for($member)->create(['points' => 4200]);

    $counts = $this->sync->handle($this->team, [apiMember('uid-1', 'Alpha')]);

    expect($counts['reactivated'])->toBe(1)
        ->and($counts['added'])->toBe(0)
        ->and($member->fresh()->is_active)->toBeTrue()
        ->and($member->fresh()->scores()->sum('points'))->toBe(4200)
        ->and($this->team->roster()->active()->count())->toBe(1);
});

it('links existing members to their uid on the first sync', function () {
    $member = Member::factory()->for($this->team)->create(['uid' => null, 'name' => 'Alpha']);

    $this->sync->handle($this->team, [apiMember('uid-1', 'Alpha')]);

    expect($member->fresh()->uid)->toBe('uid-1')
        ->and($this->team->roster()->count())->toBe(1);
});

it('links a member whose stored name was mangled by OCR', function () {
    $member = Member::factory()->for($this->team)->create(['uid' => null, 'name' => 'Commander1O1']);

    $counts = $this->sync->handle($this->team, [apiMember('uid-1', 'Commander101')]);

    expect($counts['added'])->toBe(0)
        ->and($member->fresh()->uid)->toBe('uid-1')
        ->and($member->fresh()->name)->toBe('Commander101');
});

it('tracks renames without creating a duplicate', function () {
    $member = Member::factory()->for($this->team)->create(['uid' => 'uid-1', 'name' => 'Alpha']);

    $counts = $this->sync->handle($this->team, [apiMember('uid-1', 'AlphaReborn')]);

    expect($counts['updated'])->toBe(1)
        ->and($counts['added'])->toBe(0)
        ->and($member->fresh()->name)->toBe('AlphaReborn')
        ->and($this->team->roster()->count())->toBe(1);
});

it('reports untouched members as unchanged', function () {
    Member::factory()->for($this->team)->create([
        'uid' => 'uid-1',
        'name' => 'Alpha',
        'position' => MemberPosition::R1,
    ]);

    $counts = $this->sync->handle($this->team, [apiMember('uid-1', 'Alpha', rank: 1)]);

    expect($counts)->toMatchArray(['added' => 0, 'updated' => 0, 'deactivated' => 0, 'unchanged' => 1]);
});

it('maps api ranks onto roster positions', function () {
    $this->sync->handle($this->team, [
        apiMember('uid-1', 'Leader', rank: 5),
        apiMember('uid-2', 'Officer', rank: 4),
        apiMember('uid-3', 'Elite', rank: 3),
        apiMember('uid-4', 'Veteran', rank: 2),
        apiMember('uid-5', 'Rookie', rank: 1),
    ]);

    expect($this->team->roster()->pluck('position', 'name')->map->value->all())->toBe([
        'Leader' => 'R5',
        'Officer' => 'R4',
        'Elite' => 'R3',
        'Veteran' => 'R2',
        'Rookie' => 'R1',
    ]);
});

it('refuses to empty the roster when the alliance returns nothing', function () {
    Member::factory()->for($this->team)->create();

    expect(fn () => $this->sync->handle($this->team, []))
        ->toThrow(InvalidArgumentException::class);

    expect($this->team->roster()->count())->toBe(1);
});

it('rejects a roster that breaches a position cap', function () {
    Member::factory()->for($this->team)->create(['name' => 'Existing']);

    $rows = collect(range(1, 2))
        ->map(fn (int $i): array => apiMember("uid-{$i}", "Leader{$i}", rank: 5))
        ->all();

    expect(fn () => $this->sync->handle($this->team, $rows))
        ->toThrow(ValidationException::class);

    expect($this->team->roster()->pluck('name')->all())->toBe(['Existing']);
});

it('collapses duplicate uids in the api response', function () {
    $counts = $this->sync->handle($this->team, [
        apiMember('uid-1', 'Alpha'),
        apiMember('uid-1', 'Alpha'),
    ]);

    expect($counts['added'])->toBe(1)
        ->and($this->team->roster()->count())->toBe(1);
});

it('frees a capped position when its holder leaves in the same sync', function () {
    Member::factory()->for($this->team)->create([
        'uid' => 'uid-old',
        'name' => 'OldLeader',
        'position' => MemberPosition::R5,
    ]);

    $counts = $this->sync->handle($this->team, [apiMember('uid-new', 'NewLeader', rank: 5)]);

    expect($counts['added'])->toBe(1)
        ->and($counts['deactivated'])->toBe(1)
        ->and($this->team->roster()->active()->where('position', 'R5')->pluck('name')->all())->toBe(['NewLeader']);
});

it('reactivates a departed member when they are added back by hand', function () {
    $member = Member::factory()->for($this->team)->inactive()->create(['name' => 'Alpha']);

    $restored = app(CreateMember::class)
        ->handle($this->team, 'Alpha', MemberPosition::R2);

    expect($restored->id)->toBe($member->id)
        ->and($restored->is_active)->toBeTrue()
        ->and($restored->position)->toBe(MemberPosition::R2)
        ->and($this->team->roster()->count())->toBe(1);
});

it('ignores departed members when enforcing position caps', function () {
    Member::factory()->for($this->team)->inactive()->create([
        'name' => 'FormerLeader',
        'position' => MemberPosition::R5,
    ]);

    $created = app(CreateMember::class)
        ->handle($this->team, 'NewLeader', MemberPosition::R5);

    expect($created->position)->toBe(MemberPosition::R5)
        ->and($this->team->roster()->active()->where('position', 'R5')->count())->toBe(1);
});

it('records the name of a newly imported member as an alias', function () {
    $this->sync->handle($this->team, [apiMember('uid-1', 'Alpha')]);

    expect($this->team->roster()->sole()->aliases->pluck('name')->all())->toBe(['Alpha']);
});

it('keeps both names when a member matched by uid renames', function () {
    $member = Member::factory()->for($this->team)->create(['uid' => 'uid-1', 'name' => 'Alpha']);

    $this->sync->handle($this->team, [apiMember('uid-1', 'AlphaReborn')]);

    expect($member->fresh()->name)->toBe('AlphaReborn')
        ->and($member->fresh()->aliases->pluck('name')->all())
        ->toEqualCanonicalizing(['Alpha', 'AlphaReborn']);
});

it('does not duplicate an alias across repeated syncs', function () {
    $this->sync->handle($this->team, [apiMember('uid-1', 'Alpha')]);
    $this->sync->handle($this->team, [apiMember('uid-1', 'Alpha')]);

    expect($this->team->roster()->sole()->aliases()->count())->toBe(1);
});

it('records the stored name of a member that predates aliases', function () {
    $member = Member::factory()->for($this->team)->create(['uid' => null, 'name' => 'Commander1O1']);

    $this->sync->handle($this->team, [apiMember('uid-1', 'Commander101')]);

    expect($member->fresh()->aliases->pluck('name')->all())
        ->toEqualCanonicalizing(['Commander1O1', 'Commander101']);
});

it('matches a renamed member by a former alias on a later import', function () {
    $member = Member::factory()->for($this->team)->create(['uid' => 'uid-1', 'name' => 'Alpha']);
    $this->sync->handle($this->team, [apiMember('uid-1', 'CompletelyDifferent')]);

    $roster = $this->team->roster()->with('aliases')->get();

    expect(app(RosterNameMatcher::class)->suggest('Alpha', $roster)?->id)->toBe($member->id);
});

it('drops the alias rows when a member is deleted', function () {
    $member = Member::factory()->for($this->team)->create();
    $member->recordAlias('Whatever');

    $member->delete();

    expect(MemberAlias::count())->toBe(0);
});

it('touches aliases only to eager load them when re-syncing an unchanged roster', function () {
    $rows = [apiMember('uid-1', 'Alpha'), apiMember('uid-2', 'Bravo')];

    $this->sync->handle($this->team, $rows);

    DB::enableQueryLog();
    $this->sync->handle($this->team, $rows);
    $aliasQueries = collect(DB::getRawQueryLog())
        ->pluck('raw_query')
        ->filter(fn (string $query): bool => str_contains($query, 'member_aliases'));
    DB::disableQueryLog();

    // The single eager load, and no per-member lookup or insert behind it.
    expect($aliasQueries)->toHaveCount(1)
        ->and($aliasQueries->first())->toStartWith('select')
        ->and(MemberAlias::count())->toBe(2);
});
