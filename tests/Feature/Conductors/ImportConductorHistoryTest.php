<?php

use App\Models\ConductorAssignment;
use App\Models\Member;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Get the conductor recorded for each day, keyed by date.
 *
 * The `assigned_on` cast writes a full timestamp, so reading the days back
 * through the model is more reliable than comparing a bare date in SQL.
 *
 * @return Collection<string, string>
 */
function conductorsByDate(): Collection
{
    return ConductorAssignment::with('member')->get()
        ->mapWithKeys(fn (ConductorAssignment $assignment): array => [
            $assignment->assigned_on->toDateString() => $assignment->member->name,
        ]);
}

/**
 * Build the roster the stub history file is written against.
 *
 * @return array{0: User, 1: Team}
 */
function conductorHistoryRoster(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;

    Member::factory()->for($team)->create(['name' => 'Whiskey Brain']);
    Member::factory()->for($team)->create(['name' => 'Dark Cities']);
    Member::factory()->for($team)->create(['name' => 'Moon156915']);

    // The stub refers to this member by a name they have since changed.
    Member::factory()->for($team)->create(['name' => 'Renamed Now'])->recordAlias('Old Name');

    return [$user, $team];
}

const CONDUCTOR_HISTORY_STUB = 'tests/stubs/conductors/history.csv';

test('it imports assignments for every member the file resolves to', function () {
    [, $team] = conductorHistoryRoster();

    $this->artisan('import:conductor-history', [
        'team' => $team->slug,
        'path' => base_path(CONDUCTOR_HISTORY_STUB),
    ])->assertSuccessful();

    expect(ConductorAssignment::count())->toBe(5)
        ->and(conductorsByDate()->get('2026-08-15'))->toBe('Whiskey Brain')
        ->and(ConductorAssignment::first()->team_id)->toBe($team->id);
});

test('it resolves a name that differs only by case and spacing', function () {
    [, $team] = conductorHistoryRoster();

    $this->artisan('import:conductor-history', [
        'team' => $team->slug,
        'path' => base_path(CONDUCTOR_HISTORY_STUB),
    ])->assertSuccessful();

    expect(conductorsByDate()->get('2026-08-14'))->toBe('Dark Cities');
});

test('it resolves a member by a name they no longer use', function () {
    [, $team] = conductorHistoryRoster();

    $this->artisan('import:conductor-history', [
        'team' => $team->slug,
        'path' => base_path(CONDUCTOR_HISTORY_STUB),
    ])->assertSuccessful();

    expect(conductorsByDate()->get('2026-08-12'))->toBe('Renamed Now');
});

test('it records the original hand-typed spelling as an alias', function () {
    [, $team] = conductorHistoryRoster();

    $this->artisan('import:conductor-history', [
        'team' => $team->slug,
        'path' => base_path(CONDUCTOR_HISTORY_STUB),
    ])->assertSuccessful();

    $this->assertDatabaseHas('member_aliases', [
        'member_id' => Member::where('name', 'Moon156915')->value('id'),
        'name' => 'Moon',
    ]);
});

test('it does not duplicate an alias on a second run', function () {
    [, $team] = conductorHistoryRoster();

    foreach (range(1, 2) as $ignored) {
        $this->artisan('import:conductor-history', [
            'team' => $team->slug,
            'path' => base_path(CONDUCTOR_HISTORY_STUB),
        ])->assertSuccessful();
    }

    expect(Member::where('name', 'Moon156915')->first()->aliases()->where('name', 'Moon')->count())
        ->toBe(1);
});

test('it skips an unknown name without creating a member', function () {
    [, $team] = conductorHistoryRoster();

    $this->artisan('import:conductor-history', [
        'team' => $team->slug,
        'path' => base_path(CONDUCTOR_HISTORY_STUB),
    ])
        ->expectsOutputToContain('Ghost Of Nobody')
        ->assertSuccessful();

    $this->assertDatabaseMissing('members', ['name' => 'Ghost Of Nobody']);

    expect(conductorsByDate())->not->toHaveKey('2026-08-10');
});

test('it refuses a merely similar name rather than guessing', function () {
    [, $team] = conductorHistoryRoster();

    // "Whiskey Braln" is close enough that the OCR matcher's similarity fallback
    // would claim it. An unattended backfill must not write that guess to history.
    $this->artisan('import:conductor-history', [
        'team' => $team->slug,
        'path' => base_path(CONDUCTOR_HISTORY_STUB),
    ])->assertSuccessful();

    expect(conductorsByDate())->not->toHaveKey('2026-08-11');
});

test('it skips a malformed row and keeps going', function () {
    [, $team] = conductorHistoryRoster();

    $this->artisan('import:conductor-history', [
        'team' => $team->slug,
        'path' => base_path(CONDUCTOR_HISTORY_STUB),
    ])
        ->expectsOutputToContain('not-a-date')
        ->assertSuccessful();

    expect(ConductorAssignment::count())->toBe(5);
});

test('re-running changes nothing', function () {
    [, $team] = conductorHistoryRoster();

    $this->artisan('import:conductor-history', [
        'team' => $team->slug,
        'path' => base_path(CONDUCTOR_HISTORY_STUB),
    ])->assertSuccessful();

    $this->artisan('import:conductor-history', [
        'team' => $team->slug,
        'path' => base_path(CONDUCTOR_HISTORY_STUB),
    ])
        ->expectsOutputToContain('0 new, 0 corrected, 5 already current')
        ->assertSuccessful();

    expect(ConductorAssignment::count())->toBe(5);
});

test('it corrects an assignment pointing at the wrong member', function () {
    [, $team] = conductorHistoryRoster();

    $wrong = Member::factory()->for($team)->create(['name' => 'Wrong Person']);

    ConductorAssignment::create([
        'team_id' => $team->id,
        'member_id' => $wrong->id,
        'assigned_on' => '2026-08-15',
    ]);

    $this->artisan('import:conductor-history', [
        'team' => $team->slug,
        'path' => base_path(CONDUCTOR_HISTORY_STUB),
    ])
        ->expectsOutputToContain('1 corrected')
        ->assertSuccessful();

    expect(ConductorAssignment::count())->toBe(5)
        ->and(conductorsByDate()->get('2026-08-15'))->toBe('Whiskey Brain');
});

test('a dry run reports the same tally without writing', function () {
    [, $team] = conductorHistoryRoster();

    $this->artisan('import:conductor-history', [
        'team' => $team->slug,
        'path' => base_path(CONDUCTOR_HISTORY_STUB),
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('would import 5 new')
        ->assertSuccessful();

    $this->assertDatabaseCount('conductor_assignments', 0);
    $this->assertDatabaseMissing('member_aliases', ['name' => 'Moon']);
});

test('it fails on an unknown team slug', function () {
    $this->artisan('import:conductor-history', ['team' => 'nope'])
        ->expectsOutputToContain('No team found with slug [nope].')
        ->assertFailed();
});

test('it fails when the history file is missing', function () {
    [, $team] = conductorHistoryRoster();

    $this->artisan('import:conductor-history', [
        'team' => $team->slug,
        'path' => base_path('tests/stubs/conductors/nope.csv'),
    ])
        ->expectsOutputToContain('No conductor history file found')
        ->assertFailed();
});

test('the shipped history file covers every day of the recorded run', function () {
    $handle = fopen(database_path('data/conductor-history.csv'), 'r');
    $rows = [];

    while (($fields = fgetcsv($handle, escape: '')) !== false) {
        $rows[] = $fields;
    }

    fclose($handle);

    expect(array_shift($rows))->toBe(['assigned_on', 'name', 'source_name']);

    $dates = array_column($rows, 0);

    expect($dates)->toHaveCount(105)
        ->and(array_unique($dates))->toHaveCount(105)
        ->and(min($dates))->toBe('2026-05-03')
        ->and(max($dates))->toBe('2026-08-15');

    // Every row carries a name, and the run is unbroken day to day.
    foreach ($rows as $row) {
        expect(trim($row[1]))->not->toBe('');
    }

    $sorted = $dates;
    sort($sorted);

    foreach ($sorted as $offset => $date) {
        expect($date)->toBe(now()->parse('2026-05-03')->addDays($offset)->toDateString());
    }
});

test('it flags a Sunday assignment as MVP and leaves other days alone', function () {
    [, $team] = conductorHistoryRoster();

    $this->artisan('import:conductor-history', [
        'team' => $team->slug,
        'path' => base_path(CONDUCTOR_HISTORY_STUB),
    ])
        ->expectsOutputToContain('1 of those fell on a Sunday')
        ->assertSuccessful();

    $byDate = ConductorAssignment::get()
        ->mapWithKeys(fn (ConductorAssignment $a): array => [$a->assigned_on->toDateString() => $a->is_mvp]);

    // 2026-08-09 is a Sunday; 2026-08-15 is a Saturday.
    expect($byDate->get('2026-08-09'))->toBeTrue()
        ->and($byDate->get('2026-08-15'))->toBeFalse()
        ->and(ConductorAssignment::where('is_mvp', true)->count())->toBe(1);
});

test('it corrects an MVP flag that does not match the day', function () {
    [, $team] = conductorHistoryRoster();

    // A Saturday wrongly flagged, and the Sunday that should have been.
    ConductorAssignment::create([
        'team_id' => $team->id,
        'member_id' => Member::where('name', 'Whiskey Brain')->value('id'),
        'assigned_on' => '2026-08-15',
        'is_mvp' => true,
    ]);

    $this->artisan('import:conductor-history', [
        'team' => $team->slug,
        'path' => base_path(CONDUCTOR_HISTORY_STUB),
    ])
        ->expectsOutputToContain('1 corrected')
        ->assertSuccessful();

    expect(ConductorAssignment::where('is_mvp', true)->count())->toBe(1)
        ->and(ConductorAssignment::whereDate('assigned_on', '2026-08-09')->value('is_mvp'))->toBeTrue();
});

test('the Sunday flag survives a re-run without churn', function () {
    [, $team] = conductorHistoryRoster();

    foreach (range(1, 2) as $ignored) {
        $this->artisan('import:conductor-history', [
            'team' => $team->slug,
            'path' => base_path(CONDUCTOR_HISTORY_STUB),
        ])->assertSuccessful();
    }

    expect(ConductorAssignment::where('is_mvp', true)->count())->toBe(1);
});
