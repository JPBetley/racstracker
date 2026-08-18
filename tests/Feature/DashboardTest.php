<?php

use App\Models\ConductorAssignment;
use App\Models\Member;
use App\Models\Score;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});
test('the dashboard shows who has the train today and tomorrow', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $today = Member::factory()->for($team)->create(['name' => 'Ashwood']);
    $tomorrow = Member::factory()->for($team)->create(['name' => 'Bexley']);

    ConductorAssignment::factory()->for($team)->for($today)->on(CarbonImmutable::today())->create();
    ConductorAssignment::factory()->for($team)->for($tomorrow)->on(CarbonImmutable::today()->addDay())->create();

    Livewire::actingAs($user)
        ->test('upcoming-conductors')
        ->call('setTimezone', 'UTC')
        ->assertSeeHtml('data-test="dashboard-conductor-today"')
        ->assertSeeHtml('data-test="dashboard-conductor-tomorrow"')
        ->assertSee('Ashwood')
        ->assertSee('Bexley');
});

test('the conductor card marks an mvp day', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $member = Member::factory()->for($team)->create();

    ConductorAssignment::factory()->for($team)->for($member)->on(CarbonImmutable::today())->mvp()->create();

    Livewire::actingAs($user)
        ->test('upcoming-conductors')
        ->call('setTimezone', 'UTC')
        ->assertSeeHtml('data-test="dashboard-conductor-mvp-badge"');
});

test('the conductor card says so when a day is unassigned', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('upcoming-conductors')
        ->call('setTimezone', 'UTC')
        ->assertSee('Nobody assigned');
});

test('the conductor card ignores days outside today and tomorrow', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $past = Member::factory()->for($team)->create(['name' => 'Yesterday']);
    $future = Member::factory()->for($team)->create(['name' => 'DayAfter']);

    ConductorAssignment::factory()->for($team)->for($past)->on(CarbonImmutable::today()->subDay())->create();
    ConductorAssignment::factory()->for($team)->for($future)->on(CarbonImmutable::today()->addDays(2))->create();

    Livewire::actingAs($user)
        ->test('upcoming-conductors')
        ->call('setTimezone', 'UTC')
        ->assertDontSee('Yesterday')
        ->assertDontSee('DayAfter');
});

test('the conductor card only shows the current team', function () {
    $user = User::factory()->create();
    $other = Member::factory()->create(['name' => 'Outsider']);

    ConductorAssignment::factory()
        ->for($other->team)
        ->for($other)
        ->on(CarbonImmutable::today())
        ->create();

    Livewire::actingAs($user)
        ->test('upcoming-conductors')
        ->call('setTimezone', 'UTC')
        ->assertDontSee('Outsider');
});

test('the conductor card holds a skeleton until the browser reports its timezone', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $member = Member::factory()->for($team)->create(['name' => 'Ashwood']);

    ConductorAssignment::factory()->for($team)->for($member)->on(CarbonImmutable::today())->create();

    // No probe yet: naming a conductor here would guess the day in UTC.
    $component = Livewire::actingAs($user)
        ->test('upcoming-conductors')
        ->assertSeeHtml('data-test="dashboard-conductor-skeleton"')
        ->assertDontSee('Ashwood');

    $component->call('setTimezone', 'UTC')
        ->assertDontSeeHtml('data-test="dashboard-conductor-skeleton"')
        ->assertSee('Ashwood');
});

test('the top vs card ranks the three highest scorers of the latest week', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $week = CarbonImmutable::parse('2026-08-10');

    foreach (['Ashwood' => 500, 'Bexley' => 900, 'Corvid' => 700, 'Drayton' => 100] as $name => $points) {
        $member = Member::factory()->for($team)->create(['name' => $name]);
        Score::factory()->for($member)->forWeek($week)->create(['points' => $points]);
    }

    $leaders = Livewire::actingAs($user)
        ->test('top-vs-scores')
        ->assertSee('Aug 10 – Aug 15')
        ->assertDontSee('Drayton')
        ->instance()
        ->leaders();

    expect(collect($leaders)->map(fn ($score) => $score->member->name)->all())
        ->toBe(['Bexley', 'Corvid', 'Ashwood']);
});

test('the top vs card reads the most recent week on record', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $old = Member::factory()->for($team)->create(['name' => 'OldWeek']);
    $recent = Member::factory()->for($team)->create(['name' => 'RecentWeek']);

    Score::factory()->for($old)->forWeek(CarbonImmutable::parse('2026-08-03'))->create(['points' => 9_000_000]);
    Score::factory()->for($recent)->forWeek(CarbonImmutable::parse('2026-08-10'))->create(['points' => 10]);

    Livewire::actingAs($user)
        ->test('top-vs-scores')
        ->assertSee('RecentWeek')
        ->assertDontSee('OldWeek');
});

test('the top vs card breaks a tie by name', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $week = CarbonImmutable::parse('2026-08-10');

    foreach (['Corvid', 'Ashwood', 'Bexley'] as $name) {
        $member = Member::factory()->for($team)->create(['name' => $name]);
        Score::factory()->for($member)->forWeek($week)->create(['points' => 500]);
    }

    $leaders = Livewire::actingAs($user)->test('top-vs-scores')->instance()->leaders();

    expect(collect($leaders)->map(fn ($score) => $score->member->name)->all())
        ->toBe(['Ashwood', 'Bexley', 'Corvid']);
});

test('the top vs card ignores other teams', function () {
    $user = User::factory()->create();
    $outsider = Member::factory()->create(['name' => 'Outsider']);

    Score::factory()->for($outsider)->forWeek(CarbonImmutable::parse('2026-08-10'))->create(['points' => 9_000_000]);

    Livewire::actingAs($user)
        ->test('top-vs-scores')
        ->assertDontSee('Outsider')
        ->assertSeeHtml('data-test="dashboard-top-vs-empty"');
});

test('the top vs card says so when no scores exist', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('top-vs-scores')
        ->assertSeeHtml('data-test="dashboard-top-vs-empty"')
        ->assertSee('No scores recorded yet');
});
