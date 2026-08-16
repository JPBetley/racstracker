<?php

use App\Actions\Scores\SaveScore;
use App\Models\Member;
use App\Models\Score;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// 2025-01-06 is a Monday, so it is the start of the scoring week.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2025-01-06 10:00:00'));
});

/**
 * @return array{0: User, 1: Team}
 */
function userWithTeam(): array
{
    $user = User::factory()->create();

    return [$user, $user->currentTeam];
}

test('guests are redirected from the scores page', function () {
    User::factory()->create();

    $this->get(route('scores.index'))->assertRedirect(route('login'));
});

test('scores page loads for an authenticated team member', function () {
    [$user] = userWithTeam();

    $this->actingAs($user)
        ->get(route('scores.index'))
        ->assertOk();
});

test('users cannot view the scores page of a team they do not belong to', function () {
    [$user] = userWithTeam();
    $otherTeam = Team::factory()->create();

    $this->actingAs($user)
        ->get(route('scores.index', ['current_team' => $otherTeam->slug]))
        ->assertForbidden();
});

test('entering a value records the score for the active week', function () {
    [$user, $team] = userWithTeam();
    $member = Member::factory()->for($team)->create();

    Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->set("grid.{$member->id}", '1500')
        ->call('saveWeek')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('scores', [
        'member_id' => $member->id,
        'week_start' => '2025-01-06 00:00:00',
        'points' => 1500,
    ]);

    expect($member->scores()->count())->toBe(1);
});

test('each member records a single score for the week', function () {
    [$user, $team] = userWithTeam();
    $first = Member::factory()->for($team)->create();
    $second = Member::factory()->for($team)->create();

    Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->set("grid.{$first->id}", '100')
        ->set("grid.{$second->id}", '200')
        ->call('saveWeek')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('scores', ['member_id' => $first->id, 'week_start' => '2025-01-06 00:00:00', 'points' => 100]);
    $this->assertDatabaseHas('scores', ['member_id' => $second->id, 'week_start' => '2025-01-06 00:00:00', 'points' => 200]);
    expect(Score::count())->toBe(2);
});

test('scores are recorded against the week being viewed', function () {
    [$user, $team] = userWithTeam();
    $member = Member::factory()->for($team)->create();

    Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->call('previousWeek')
        ->set("grid.{$member->id}", '750')
        ->call('saveWeek')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('scores', [
        'member_id' => $member->id,
        'week_start' => '2024-12-30 00:00:00',
        'points' => 750,
    ]);
});

test('entered scores are not persisted until the week is saved', function () {
    [$user, $team] = userWithTeam();
    $member = Member::factory()->for($team)->create();

    Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->set("grid.{$member->id}", '1500')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('scores', ['member_id' => $member->id]);
});

test('blanking a cell removes the recorded score', function () {
    [$user, $team] = userWithTeam();
    $member = Member::factory()->for($team)->create();
    Score::factory()->for($member)->forWeek(CarbonImmutable::parse('2025-01-06'))->create(['points' => 900]);

    Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->set("grid.{$member->id}", '')
        ->call('saveWeek')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('scores', [
        'member_id' => $member->id,
    ]);
});

test('negative scores are rejected', function () {
    [$user, $team] = userWithTeam();
    $member = Member::factory()->for($team)->create();

    Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->set("grid.{$member->id}", '-5')
        ->call('saveWeek')
        ->assertHasErrors("grid.{$member->id}");

    $this->assertDatabaseMissing('scores', ['member_id' => $member->id]);
});

test('the weekly leaderboard ranks members by their weekly score', function () {
    [$user, $team] = userWithTeam();
    $week = CarbonImmutable::parse('2025-01-06');

    $low = Member::factory()->for($team)->create(['name' => 'Low']);
    $high = Member::factory()->for($team)->create(['name' => 'High']);
    $mid = Member::factory()->for($team)->create(['name' => 'Mid']);

    Score::factory()->for($low)->forWeek($week)->create(['points' => 100]);
    Score::factory()->for($high)->forWeek($week)->create(['points' => 500]);
    Score::factory()->for($mid)->forWeek($week)->create(['points' => 300]);

    $ranking = Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->instance()
        ->weeklyRanking();

    expect(array_map(fn ($entry) => $entry['member']->name, $ranking))
        ->toBe(['High', 'Mid', 'Low']);
});

test('a member without a score for the week ranks with zero points', function () {
    [$user, $team] = userWithTeam();

    $scored = Member::factory()->for($team)->create(['name' => 'Scored']);
    Member::factory()->for($team)->create(['name' => 'Unscored']);

    Score::factory()->for($scored)->forWeek(CarbonImmutable::parse('2025-01-06'))->create(['points' => 400]);

    $ranking = Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->instance()
        ->weeklyRanking();

    expect($ranking[1]['member']->name)->toBe('Unscored')
        ->and($ranking[1]['points'])->toBe(0);
});

test('week navigation shifts the visible week and caps at the current week', function () {
    [$user] = userWithTeam();

    $component = Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->assertSet('weekOffset', 0)
        ->assertSee('Jan 6 – Jan 11');

    // Cannot move into the future.
    $component->call('nextWeek')->assertSet('weekOffset', 0);

    // Moving back a week shows the prior Monday-Saturday range.
    $component->call('previousWeek')
        ->assertSet('weekOffset', -1)
        ->assertSee('Dec 30 – Jan 4');
});

test('the visible week follows the user timezone', function () {
    // 02:00 UTC Monday is still 21:00 Sunday in New York, so the week has not rolled over yet.
    $this->travelTo(CarbonImmutable::parse('2025-01-06 02:00:00', 'UTC'));
    [$user] = userWithTeam();

    Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->assertSee('Jan 6 – Jan 11')
        ->call('setTimezone', 'America/New_York')
        ->assertSee('Dec 30 – Jan 4');
});

test('scores can only be recorded for members of the current team', function () {
    [$user] = userWithTeam();
    $foreignMember = Member::factory()->for(Team::factory())->create();

    Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->set("grid.{$foreignMember->id}", '100')
        ->call('saveWeek')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('scores', ['member_id' => $foreignMember->id]);
});

test('the save score action normalises any date to the start of its week', function () {
    $member = Member::factory()->create();

    // Thursday of the week beginning Monday 2025-01-06.
    app(SaveScore::class)->handle($member, CarbonImmutable::parse('2025-01-09'), 100);

    $this->assertDatabaseHas('scores', [
        'member_id' => $member->id,
        'week_start' => '2025-01-06 00:00:00',
        'points' => 100,
    ]);
});

test('the save score action upserts an existing week', function () {
    $member = Member::factory()->create();
    $monday = CarbonImmutable::parse('2025-01-06');

    app(SaveScore::class)->handle($member, $monday, 100);
    app(SaveScore::class)->handle($member, $monday->addDays(3), 250);

    expect($member->scores()->count())->toBe(1);
    $this->assertDatabaseHas('scores', [
        'member_id' => $member->id,
        'week_start' => '2025-01-06 00:00:00',
        'points' => 250,
    ]);
});

test('a departed member still appears in a week they scored in', function () {
    [$user, $team] = userWithTeam();

    $departed = Member::factory()->for($team)->inactive()->create(['name' => 'Departed']);
    Score::factory()->for($departed)->forWeek(CarbonImmutable::parse('2025-01-01'))->create(['points' => 5000]);

    Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->call('previousWeek')
        ->assertSee('Departed');
});

test('a departed member is hidden from a week they did not score in', function () {
    [$user, $team] = userWithTeam();

    Member::factory()->for($team)->inactive()->create(['name' => 'Departed']);
    Member::factory()->for($team)->create(['name' => 'Current']);

    Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->assertSee('Current')
        ->assertDontSee('Departed');
});

test('the members page lists only active members', function () {
    [$user, $team] = userWithTeam();

    Member::factory()->for($team)->create(['name' => 'Current']);
    Member::factory()->for($team)->inactive()->create(['name' => 'Departed']);

    Livewire::actingAs($user)
        ->test('pages::members.index')
        ->assertSee('Current')
        ->assertDontSee('Departed');
});
