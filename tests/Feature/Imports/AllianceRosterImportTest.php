<?php

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\TeamRole;
use App\Jobs\Imports\SyncAllianceMembers;
use App\LastWar\Contracts\LastWarApi;
use App\LastWar\LastWarApiException;
use App\Models\Import;
use App\Models\Member;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * Create a team with an owner so imports can be attributed.
 */
function teamWithOwner(array $attributes = []): Team
{
    $team = Team::factory()->create($attributes);
    $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Owner->value]);

    return $team;
}

it('queues an alliance import for the team', function () {
    Queue::fake();

    $team = teamWithOwner(['slug' => 'racs', 'alliance_id' => str_repeat('a', 32)]);

    $this->artisan('import:alliance racs')
        ->expectsOutputToContain('Queued alliance roster import')
        ->assertSuccessful();

    $import = Import::sole();

    expect($import->type)->toBe(ImportType::AllianceRoster)
        ->and($import->team_id)->toBe($team->id)
        ->and($import->payload['alliance_id'])->toBe(str_repeat('a', 32));

    Queue::assertPushed(SyncAllianceMembers::class);
});

it('prefers the alliance id passed on the command line', function () {
    Queue::fake();

    teamWithOwner(['slug' => 'racs', 'alliance_id' => 'stored']);

    $this->artisan('import:alliance racs --alliance=override')->assertSuccessful();

    expect(Import::sole()->payload['alliance_id'])->toBe('override');
});

it('falls back to the configured alliance id', function () {
    Queue::fake();

    config()->set('services.lastwar.alliance_id', 'from-config');
    teamWithOwner(['slug' => 'racs', 'alliance_id' => null]);

    $this->artisan('import:alliance racs')->assertSuccessful();

    expect(Import::sole()->payload['alliance_id'])->toBe('from-config');
});

it('fails when the team has no alliance id anywhere', function () {
    config()->set('services.lastwar.alliance_id', null);
    teamWithOwner(['slug' => 'racs', 'alliance_id' => null]);

    $this->artisan('import:alliance racs')
        ->expectsOutputToContain('has no alliance ID')
        ->assertFailed();

    expect(Import::count())->toBe(0);
});

it('fails when no api key is configured', function () {
    config()->set('services.lastwar.key', null);
    teamWithOwner(['slug' => 'racs', 'alliance_id' => 'abc']);

    $this->artisan('import:alliance racs')
        ->expectsOutputToContain('No Last War API key configured')
        ->assertFailed();

    expect(Import::count())->toBe(0);
});

it('fails for an unknown team', function () {
    $this->artisan('import:alliance nope')
        ->expectsOutputToContain('No team found')
        ->assertFailed();
});

it('syncs the roster and records the outcome on the import', function () {
    $team = teamWithOwner(['slug' => 'racs', 'alliance_id' => 'abc']);

    Member::factory()->for($team)->create(['uid' => 'uid-leaver', 'name' => 'Leaver']);
    Member::factory()->for($team)->create(['uid' => 'uid-stay', 'name' => 'Stayer']);

    $this->mock(LastWarApi::class)
        ->shouldReceive('allianceMembers')
        ->once()
        ->with('abc')
        ->andReturn([
            ['uid' => 'uid-stay', 'name' => 'Stayer', 'rank' => 3, 'power' => 1],
            ['uid' => 'uid-new', 'name' => 'Newcomer', 'rank' => 5, 'power' => 2],
        ]);

    $import = Import::factory()->for($team)->create([
        'type' => ImportType::AllianceRoster,
        'payload' => ['alliance_id' => 'abc'],
    ]);

    (new SyncAllianceMembers($import))->handle();

    expect($import->fresh()->results)
        ->toMatchArray(['alliance_id' => 'abc', 'total' => 2, 'added' => 1, 'deactivated' => 1])
        ->and($team->roster()->active()->pluck('name')->all())->toEqualCanonicalizing(['Stayer', 'Newcomer'])
        ->and($team->roster()->inactive()->pluck('name')->all())->toBe(['Leaver']);
});

it('fails the import when the alliance id is missing at run time', function () {
    $team = teamWithOwner(['slug' => 'racs', 'alliance_id' => null]);
    config()->set('services.lastwar.alliance_id', null);

    $import = Import::factory()->for($team)->create([
        'type' => ImportType::AllianceRoster,
        'payload' => [],
    ]);

    expect(fn () => (new SyncAllianceMembers($import))->handle())
        ->toThrow(RuntimeException::class, 'no Last War alliance ID');
});

it('marks the import failed when the api errors', function () {
    $team = teamWithOwner(['slug' => 'racs', 'alliance_id' => 'abc']);

    $this->mock(LastWarApi::class)
        ->shouldReceive('allianceMembers')
        ->andThrow(new LastWarApiException('Invalid API key.'));

    $import = Import::factory()->for($team)->create([
        'type' => ImportType::AllianceRoster,
        'payload' => ['alliance_id' => 'abc'],
        'status' => ImportStatus::Processing,
    ]);

    expect(fn () => (new SyncAllianceMembers($import))->handle())
        ->toThrow(LastWarApiException::class);

    expect($team->roster()->count())->toBe(0);
});
