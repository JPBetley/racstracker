<?php

use App\Actions\Scores\SaveScore;
use App\Models\Member;
use App\Models\Score;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
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

test('entering a value records a score for the correct day', function () {
    [$user, $team] = userWithTeam();
    $member = Member::factory()->for($team)->create();

    Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->set("grid.{$member->id}.1", '1500')
        ->call('saveWeek')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('scores', [
        'member_id' => $member->id,
        'date' => '2025-01-06 00:00:00',
        'points' => 1500,
    ]);
});

test('multiple entered scores are saved together for the week', function () {
    [$user, $team] = userWithTeam();
    $member = Member::factory()->for($team)->create();

    Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->set("grid.{$member->id}.1", '100')
        ->set("grid.{$member->id}.3", '200')
        ->set("grid.{$member->id}.6", '300')
        ->call('saveWeek')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('scores', ['member_id' => $member->id, 'date' => '2025-01-06 00:00:00', 'points' => 100]);
    $this->assertDatabaseHas('scores', ['member_id' => $member->id, 'date' => '2025-01-08 00:00:00', 'points' => 200]);
    $this->assertDatabaseHas('scores', ['member_id' => $member->id, 'date' => '2025-01-11 00:00:00', 'points' => 300]);
    expect($member->scores()->count())->toBe(3);
});

test('entered scores are not persisted until the week is saved', function () {
    [$user, $team] = userWithTeam();
    $member = Member::factory()->for($team)->create();

    Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->set("grid.{$member->id}.1", '1500')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('scores', ['member_id' => $member->id]);
});

test('blanking a cell removes the recorded score', function () {
    [$user, $team] = userWithTeam();
    $member = Member::factory()->for($team)->create();
    Score::factory()->for($member)->onDate(CarbonImmutable::parse('2025-01-06'))->create(['points' => 900]);

    Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->set("grid.{$member->id}.1", '')
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
        ->set("grid.{$member->id}.1", '-5')
        ->call('saveWeek')
        ->assertHasErrors("grid.{$member->id}.1");

    $this->assertDatabaseMissing('scores', ['member_id' => $member->id]);
});

test('the weekly row total sums Monday through Saturday', function () {
    [$user, $team] = userWithTeam();
    $member = Member::factory()->for($team)->create();

    foreach ([1, 2, 6] as $day) {
        Score::factory()->for($member)
            ->onDate(CarbonImmutable::parse('2025-01-06')->addDays($day - 1))
            ->create(['points' => 100]);
    }

    $total = Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->instance()
        ->rowTotal($member->id);

    expect($total)->toBe(300);
});

test('the weekly leaderboard ranks members by total points', function () {
    [$user, $team] = userWithTeam();
    $monday = CarbonImmutable::parse('2025-01-06');

    $low = Member::factory()->for($team)->create(['name' => 'Low']);
    $high = Member::factory()->for($team)->create(['name' => 'High']);
    $mid = Member::factory()->for($team)->create(['name' => 'Mid']);

    Score::factory()->for($low)->onDate($monday)->create(['points' => 100]);
    Score::factory()->for($high)->onDate($monday)->create(['points' => 500]);
    Score::factory()->for($mid)->onDate($monday)->create(['points' => 300]);

    $ranking = Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->instance()
        ->weeklyRanking();

    expect(array_map(fn ($entry) => $entry['member']->name, $ranking))
        ->toBe(['High', 'Mid', 'Low']);
});

test('the daily leaderboard ranks members for the selected day', function () {
    [$user, $team] = userWithTeam();
    $monday = CarbonImmutable::parse('2025-01-06');
    $tuesday = $monday->addDay();

    $a = Member::factory()->for($team)->create(['name' => 'Ada']);
    $b = Member::factory()->for($team)->create(['name' => 'Bea']);

    // Ada wins Monday, Bea wins Tuesday.
    Score::factory()->for($a)->onDate($monday)->create(['points' => 800]);
    Score::factory()->for($b)->onDate($monday)->create(['points' => 200]);
    Score::factory()->for($a)->onDate($tuesday)->create(['points' => 100]);
    Score::factory()->for($b)->onDate($tuesday)->create(['points' => 900]);

    $component = Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->call('selectDay', 2);

    $ranking = $component->instance()->dailyRanking();

    expect(array_map(fn ($entry) => $entry['member']->name, $ranking))
        ->toBe(['Bea', 'Ada']);
});

test('the highlighted day is based on the user timezone', function () {
    // 02:00 UTC Tuesday is still 21:00 Monday in New York.
    $this->travelTo(CarbonImmutable::parse('2025-01-07 02:00:00', 'UTC'));
    [$user] = userWithTeam();

    Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->assertSet('selectedDay', 2) // Tuesday by the UTC default
        ->call('setTimezone', 'America/New_York')
        ->assertSet('selectedDay', 1); // Monday in the user's timezone
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

test('scores can only be recorded for members of the current team', function () {
    [$user] = userWithTeam();
    $foreignMember = Member::factory()->for(Team::factory())->create();

    Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->set("grid.{$foreignMember->id}.1", '100')
        ->call('saveWeek')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('scores', ['member_id' => $foreignMember->id]);
});

test('the save score action rejects Sunday dates', function () {
    $member = Member::factory()->create();

    expect(fn () => app(SaveScore::class)->handle($member, CarbonImmutable::parse('2025-01-12'), 100))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseMissing('scores', ['member_id' => $member->id]);
});

test('the save score action upserts an existing day', function () {
    $member = Member::factory()->create();
    $monday = CarbonImmutable::parse('2025-01-06');

    app(SaveScore::class)->handle($member, $monday, 100);
    app(SaveScore::class)->handle($member, $monday, 250);

    expect($member->scores()->count())->toBe(1);
    $this->assertDatabaseHas('scores', [
        'member_id' => $member->id,
        'date' => '2025-01-06 00:00:00',
        'points' => 250,
    ]);
});
