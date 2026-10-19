<?php

use App\Actions\Imports\StartImport;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\MemberPosition;
use App\Enums\TeamRole;
use App\Jobs\Imports\FetchGameVsScores;
use App\Jobs\Imports\ImportVsScores;
use App\LastWar\Game\AllianceReader;
use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\Exceptions\GateServerException;
use App\LastWar\Game\Exceptions\LoginRejectedException;
use App\LastWar\Game\Exceptions\SessionEndedException;
use App\Models\GameAccount;
use App\Models\Import;
use App\Models\Member;
use App\Models\Score;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

const RACS_ALLIANCE = 'bd07f5a7e1f547ebbd94ba24b6c3f3d3';

// Tuesday 2026-10-20 03:00 UTC is day 2 of the VS week that began Monday 2026-10-19.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 03:00:00', 'UTC'));
});

/**
 * Create an owned team with a bound game account.
 */
function gameSyncTeam(array $attributes = []): Team
{
    $team = Team::factory()->create(['alliance_id' => RACS_ALLIANCE, ...$attributes]);
    $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Owner->value]);
    GameAccount::factory()->for($team)->create();

    return $team;
}

it('queues a game vs import for each team with a bound account', function () {
    Queue::fake();

    $synced = gameSyncTeam(['slug' => 'racs']);
    Team::factory()->create(['slug' => 'unbound', 'alliance_id' => str_repeat('b', 32)]);
    gameSyncTeam(['slug' => 'no-alliance', 'alliance_id' => null]);

    $this->artisan('import:game-vs-scores')
        ->expectsOutputToContain('Queued 1 game VS score import(s) for the week of 2026-10-19.')
        ->assertSuccessful();

    $import = Import::sole();

    expect($import->team_id)->toBe($synced->id)
        ->and($import->type)->toBe(ImportType::GameVsScores)
        ->and($import->payload)->toBe(['alliance_id' => RACS_ALLIANCE, 'week_start' => '2026-10-19']);

    Queue::assertPushed(FetchGameVsScores::class);
});

it('reports when no team can sync from the game', function () {
    $this->artisan('import:game-vs-scores')
        ->expectsOutputToContain('No teams have a bound game account')
        ->assertSuccessful();
});

it('skips a team that has no owner', function () {
    Queue::fake();

    $team = Team::factory()->create(['slug' => 'ownerless', 'alliance_id' => RACS_ALLIANCE]);
    GameAccount::factory()->for($team)->create();

    $this->artisan('import:game-vs-scores')
        ->expectsOutputToContain('Skipped team [ownerless]')
        ->assertSuccessful();

    expect(Import::count())->toBe(0);
});

it('anchors the vs week to the 02:00 utc server rollover', function (string $moment, string $weekStart) {
    expect(AllianceReader::weekStartAt(CarbonImmutable::parse($moment, 'UTC'))->toDateString())->toBe($weekStart);
})->with([
    'monday before the rollover still counts as last week' => ['2026-10-19 01:30:00', '2026-10-12'],
    'monday after the rollover starts the new week' => ['2026-10-19 02:00:00', '2026-10-19'],
    'sunday rest day' => ['2026-10-25 12:00:00', '2026-10-19'],
]);

it('records the weekly game scores like a confirmed screenshot import', function () {
    $team = gameSyncTeam();
    $linked = Member::factory()->for($team)->create(['uid' => '1001', 'name' => 'Linked']);
    $unlinked = Member::factory()->for($team)->create(['uid' => null, 'name' => 'Name Only']);
    $other = Member::factory()->for($team)->create(['uid' => '1003', 'name' => 'Already Scored']);
    Score::factory()->for($other)->forWeek(CarbonImmutable::parse('2026-10-19'))->create(['points' => 1]);

    $this->mock(AllianceReader::class)
        ->shouldReceive('read')
        ->once()
        ->withArgs(fn (GameAccount $account, string $allianceId) => $account->is($team->gameAccount) && $allianceId === RACS_ALLIANCE)
        ->andReturn([
            'members' => [
                ['uid' => '1001', 'name' => 'Renamed In Game', 'rank' => 5, 'power' => 3],
                ['uid' => '1002', 'name' => 'Name Only', 'rank' => 3, 'power' => 2],
                ['uid' => '1003', 'name' => 'Already Scored', 'rank' => 3, 'power' => 1],
            ],
            'scores' => [
                ['uid' => '1001', 'name' => 'Renamed In Game', 'points' => 9_403_126],
                ['uid' => '1002', 'name' => 'name only', 'points' => 500],
                ['uid' => '1003', 'name' => 'Already Scored', 'points' => 2_000],
                ['uid' => '1999', 'name' => 'Stranger', 'points' => 7],
            ],
        ]);

    $import = Import::factory()->for($team)->create([
        'type' => ImportType::GameVsScores,
        'payload' => ['alliance_id' => RACS_ALLIANCE, 'week_start' => '2026-10-19'],
    ]);

    (new FetchGameVsScores($import))->handle();
    (new ImportVsScores($import->fresh()))->handle();

    $weekScore = fn (Member $member) => $member->scores()->whereDate('week_start', '2026-10-19')->value('points');

    expect($weekScore($linked))->toBe(9_403_126)
        ->and($weekScore($unlinked))->toBe(500)
        ->and($weekScore($other))->toBe(2_000)
        ->and($import->fresh()->results)->toMatchArray([
            'alliance_id' => RACS_ALLIANCE,
            'fetched' => 4,
            'week_start' => '2026-10-19',
            'total' => 4,
            'created' => 2,
            'updated' => 1,
            'skipped' => [['name' => 'Stranger', 'reason' => 'No matching roster member.']],
        ]);
});

it('adds a new member from the game and scores them in the same run', function () {
    $team = gameSyncTeam();
    $stayer = Member::factory()->for($team)->create(['uid' => '1001', 'name' => 'Stayer', 'position' => MemberPosition::R5]);
    $leaver = Member::factory()->for($team)->create(['uid' => '1002', 'name' => 'Leaver']);

    $this->mock(AllianceReader::class)
        ->shouldReceive('read')
        ->once()
        ->andReturn([
            'members' => [
                ['uid' => '1001', 'name' => 'Stayer', 'rank' => 5, 'power' => 2],
                ['uid' => '1004', 'name' => 'Newcomer', 'rank' => 3, 'power' => 1],
            ],
            'scores' => [
                ['uid' => '1001', 'name' => 'Stayer', 'points' => 1_000],
                ['uid' => '1004', 'name' => 'Newcomer', 'points' => 300],
            ],
        ]);

    $import = Import::factory()->for($team)->create([
        'type' => ImportType::GameVsScores,
        'payload' => ['alliance_id' => RACS_ALLIANCE, 'week_start' => '2026-10-19'],
    ]);

    (new FetchGameVsScores($import))->handle();
    (new ImportVsScores($import->fresh()))->handle();

    $newcomer = $team->roster()->where('uid', '1004')->sole();
    $weekScore = fn (Member $member) => $member->scores()->whereDate('week_start', '2026-10-19')->value('points');

    expect($newcomer->name)->toBe('Newcomer')
        ->and($weekScore($newcomer))->toBe(300)
        ->and($weekScore($stayer))->toBe(1_000)
        ->and($leaver->fresh()->is_active)->toBeFalse()
        ->and($import->fresh()->results)->toMatchArray([
            'roster' => ['added' => 1, 'reactivated' => 0, 'updated' => 0, 'deactivated' => 1, 'unchanged' => 1],
            'fetched' => 2,
            'created' => 2,
            'skipped' => [],
        ])
        ->and($import->fresh()->results)->not->toHaveKey('roster_error');
});

it('still records scores when the game roster cannot be synced', function () {
    $team = gameSyncTeam();
    $existing = Member::factory()->for($team)->create(['uid' => '1001', 'name' => 'Existing']);

    $this->mock(AllianceReader::class)
        ->shouldReceive('read')
        ->once()
        ->andReturn([
            'members' => [
                ['uid' => '1001', 'name' => 'Existing', 'rank' => 5, 'power' => 2],
                ['uid' => '1005', 'name' => 'Second Leader', 'rank' => 5, 'power' => 1],
            ],
            'scores' => [
                ['uid' => '1001', 'name' => 'Existing', 'points' => 1_000],
                ['uid' => '1005', 'name' => 'Second Leader', 'points' => 50],
            ],
        ]);

    $import = Import::factory()->for($team)->create([
        'type' => ImportType::GameVsScores,
        'payload' => ['alliance_id' => RACS_ALLIANCE, 'week_start' => '2026-10-19'],
    ]);

    (new FetchGameVsScores($import))->handle();
    (new ImportVsScores($import->fresh()))->handle();

    $results = $import->fresh()->results;

    expect($existing->scores()->whereDate('week_start', '2026-10-19')->value('points'))->toBe(1_000)
        ->and($team->roster()->count())->toBe(1)
        ->and($results)->toHaveKey('roster_error')
        ->and($results)->not->toHaveKey('roster')
        ->and($results['skipped'])->toBe([['name' => 'Second Leader', 'reason' => 'No matching roster member.']]);
});

it('fails the import when the team has no bound account', function () {
    $team = Team::factory()->create(['alliance_id' => RACS_ALLIANCE]);
    $import = Import::factory()->for($team)->create(['type' => ImportType::GameVsScores, 'payload' => []]);

    expect(fn () => (new FetchGameVsScores($import))->handle())
        ->toThrow(RuntimeException::class, 'no bound game account');
});

it('fails a rejected game login without retrying', function (Throwable $error) {
    $team = gameSyncTeam();

    $this->mock(AllianceReader::class)
        ->shouldReceive('read')
        ->andThrow($error);

    $import = Import::factory()->for($team)->create([
        'type' => ImportType::GameVsScores,
        'payload' => ['alliance_id' => RACS_ALLIANCE, 'week_start' => '2026-10-19'],
        'status' => ImportStatus::Processing,
    ]);

    $job = (new FetchGameVsScores($import))->withFakeQueueInteractions();
    $job->handle();

    $job->assertFailedWith($error::class);
    expect(Score::count())->toBe(0);
})->with([
    'login rejected' => fn () => new LoginRejectedException('The game server rejected the login (ec=28, E005).', ['E005']),
    'login key dead' => fn () => GateServerException::fromCode('211'),
    'owner logged back in' => fn () => new SessionEndedException('push.user.off'),
]);

it('rethrows a transient game failure so the queue retries it', function () {
    $team = gameSyncTeam();

    $this->mock(AllianceReader::class)
        ->shouldReceive('read')
        ->andThrow(new GameClientException('Timed out after 15s waiting for the login reply.'));

    $import = Import::factory()->for($team)->create([
        'type' => ImportType::GameVsScores,
        'payload' => ['alliance_id' => RACS_ALLIANCE, 'week_start' => '2026-10-19'],
    ]);

    $job = (new FetchGameVsScores($import))->withFakeQueueInteractions();

    expect(fn () => $job->handle())->toThrow(GameClientException::class, 'Timed out');
    $job->assertNotFailed();
    expect($job->tries)->toBe(2)
        ->and($job->backoff)->toBe([300]);
});

it('fails the whole import when the game login is rejected', function () {
    $team = gameSyncTeam();

    $this->mock(AllianceReader::class)
        ->shouldReceive('read')
        ->andThrow(new LoginRejectedException('The game server rejected the login (ec=28, E005).', ['E005']));

    $import = app(StartImport::class)->handle($team, $team->owner(), ImportType::GameVsScores, [
        'alliance_id' => RACS_ALLIANCE,
        'week_start' => '2026-10-19',
    ]);

    expect($import->fresh()->status)->toBe(ImportStatus::Failed)
        ->and(Score::count())->toBe(0);
});

it('refuses to record a week that rolled over before the fetch ran', function () {
    $team = gameSyncTeam();
    $member = Member::factory()->for($team)->create(['uid' => '1001']);
    Score::factory()->for($member)->forWeek(CarbonImmutable::parse('2026-10-12'))->create(['points' => 80_000_000]);

    $this->mock(AllianceReader::class)->shouldNotReceive('read');

    // Queued at Monday 01:30 UTC for the week of the 12th, run after the 02:00 rollover.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 02:05:00', 'UTC'));

    $import = Import::factory()->for($team)->create([
        'type' => ImportType::GameVsScores,
        'payload' => ['alliance_id' => RACS_ALLIANCE, 'week_start' => '2026-10-12'],
    ]);

    $job = (new FetchGameVsScores($import))->withFakeQueueInteractions();
    $job->handle();

    $job->assertFailedWith(RuntimeException::class);
    expect($import->fresh()->payload)->not->toHaveKey('scores')
        ->and($member->scores()->whereDate('week_start', '2026-10-12')->value('points'))->toBe(80_000_000);
});

it('schedules the nightly game vs sync at 01:30 utc', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'import:game-vs-scores'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('30 1 * * *')
        ->and($events->first()->timezone)->toBe('UTC');
});
