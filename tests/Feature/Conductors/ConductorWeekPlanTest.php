<?php

use App\Models\ConductorAssignment;
use App\Models\Member;
use App\Models\Score;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// 2025-01-12 is a Sunday, the day the week is planned on. The VS week deciding
// eligibility is the one that just closed, starting Monday 2025-01-06.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2025-01-12 10:00:00'));
});

/**
 * @return array{0: User, 1: Team}
 */
function planUserWithTeam(int $trainVsRequirement = 0, bool $desertStorm = false): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $team->update([
        'train_vs_requirement' => $trainVsRequirement,
        'train_desert_storm_requirement' => $desertStorm,
    ]);

    return [$user, $team->fresh()];
}

/**
 * Add a member to the roster with a score for the deciding VS week.
 */
function planMember(Team $team, string $name, ?int $points = null): Member
{
    $member = Member::factory()->for($team)->create(['name' => $name]);

    if ($points !== null) {
        Score::factory()->for($member)->forWeek(CarbonImmutable::parse('2025-01-06'))->create(['points' => $points]);
    }

    return $member;
}

test('guests are redirected from the plan page', function () {
    User::factory()->create();

    $this->get(route('conductors.plan'))->assertRedirect(route('login'));
});

test('the plan page loads for an authenticated team member', function () {
    [$user] = planUserWithTeam();

    $this->actingAs($user)
        ->get(route('conductors.plan'))
        ->assertOk();
});

test('the planned week runs from the coming sunday through saturday', function () {
    [$user] = planUserWithTeam();

    $component = Livewire::actingAs($user)->test('pages::conductors.plan')->instance();

    expect($component->planStart()->toDateString())->toBe('2025-01-12')
        ->and($component->scoreWeekStart()->toDateString())->toBe('2025-01-06')
        ->and(collect($component->conductorDays())->map->toDateString()->all())
        ->toBe(['2025-01-13', '2025-01-14', '2025-01-15', '2025-01-16', '2025-01-17', '2025-01-18']);
});

test('opening the wizard mid week plans the sunday still to come', function () {
    $this->travelTo(CarbonImmutable::parse('2025-01-14 10:00:00')); // Tuesday

    [$user] = planUserWithTeam();

    $component = Livewire::actingAs($user)->test('pages::conductors.plan')->instance();

    expect($component->planStart()->toDateString())->toBe('2025-01-19');
});

test('members below the train vs requirement are not candidates', function () {
    [$user, $team] = planUserWithTeam(trainVsRequirement: 1_000);

    planMember($team, 'Ashwood', 1_500);
    planMember($team, 'Bexley', 500);

    $candidates = Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->instance()
        ->candidates();

    expect(collect($candidates)->pluck('member.name')->all())->toBe(['Ashwood']);
});

test('the threshold step lists the roster by score, highest first', function () {
    [$user, $team] = planUserWithTeam();

    planMember($team, 'Ashwood', 500);
    planMember($team, 'Bexley', 1_500);
    planMember($team, 'Corvid'); // no score at all
    planMember($team, 'Drayton', 1_000);

    $rows = Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->instance()
        ->rows();

    expect(collect($rows)->pluck('member.name')->all())->toBe(['Bexley', 'Drayton', 'Ashwood', 'Corvid']);
});

test('the threshold table renders in score order', function () {
    [$user, $team] = planUserWithTeam();

    planMember($team, 'Ashwood', 500);
    planMember($team, 'Bexley', 1_500);
    planMember($team, 'Corvid');
    planMember($team, 'Drayton', 1_000);

    $html = Livewire::actingAs($user)->test('pages::conductors.plan')->html();

    preg_match_all('/<span class="font-medium">([^<]+)<\/span>/', $html, $matches);

    expect($matches[1])->toBe(['Bexley', 'Drayton', 'Ashwood', 'Corvid']);
});

test('members on the same score are listed by name', function () {
    [$user, $team] = planUserWithTeam();

    planMember($team, 'Corvid', 1_000);
    planMember($team, 'Ashwood', 1_000);
    planMember($team, 'Bexley', 1_000);

    $rows = Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->instance()
        ->rows();

    expect(collect($rows)->pluck('member.name')->all())->toBe(['Ashwood', 'Bexley', 'Corvid']);
});

test('a requirement of zero admits every active member', function () {
    [$user, $team] = planUserWithTeam(trainVsRequirement: 0);

    planMember($team, 'Ashwood', 1_500);
    planMember($team, 'Bexley'); // no score at all

    $candidates = Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->instance()
        ->candidates();

    expect(collect($candidates)->pluck('member.name')->sort()->values()->all())->toBe(['Ashwood', 'Bexley']);
});

test('a member with no score for the week counts as zero points', function () {
    [$user, $team] = planUserWithTeam(trainVsRequirement: 1);

    planMember($team, 'Ashwood', 1_500);
    planMember($team, 'Bexley');

    $candidates = Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->instance()
        ->candidates();

    expect(collect($candidates)->pluck('member.name')->all())->toBe(['Ashwood']);
});

test('inactive members are never candidates', function () {
    [$user, $team] = planUserWithTeam();

    planMember($team, 'Ashwood', 1_500);
    Member::factory()->for($team)->inactive()->create(['name' => 'Departed']);

    $candidates = Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->instance()
        ->candidates();

    expect(collect($candidates)->pluck('member.name')->all())->toBe(['Ashwood']);
});

test('the desert storm step is skipped when the team does not require it', function () {
    [$user, $team] = planUserWithTeam(desertStorm: false);

    planMember($team, 'Ashwood', 1_500);

    Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->call('nextStep')
        ->assertSet('step', 3)
        ->call('previousStep')
        ->assertSet('step', 1);
});

test('the desert storm step is shown when the team requires it', function () {
    [$user, $team] = planUserWithTeam(desertStorm: true);

    planMember($team, 'Ashwood', 1_500);

    Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->call('nextStep')
        ->assertSet('step', 2)
        ->assertSeeHtml('data-test="plan-desert-storm-step"');
});

test('desert storm participation starts ticked for everyone eligible', function () {
    [$user, $team] = planUserWithTeam(desertStorm: true);

    $ashwood = planMember($team, 'Ashwood', 1_500);
    $bexley = planMember($team, 'Bexley', 1_200);

    Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->assertSet("desertStorm.{$ashwood->id}", true)
        ->assertSet("desertStorm.{$bexley->id}", true);
});

test('unticking desert storm drops a member from the candidates', function () {
    [$user, $team] = planUserWithTeam(desertStorm: true);

    planMember($team, 'Ashwood', 1_500);
    $bexley = planMember($team, 'Bexley', 1_200);

    $candidates = Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->set("desertStorm.{$bexley->id}", false)
        ->instance()
        ->candidates();

    expect(collect($candidates)->pluck('member.name')->all())->toBe(['Ashwood']);
});

test('desert storm participation is ignored when the team does not require it', function () {
    [$user, $team] = planUserWithTeam(desertStorm: false);

    planMember($team, 'Ashwood', 1_500);
    $bexley = planMember($team, 'Bexley', 1_200);

    $candidates = Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->set("desertStorm.{$bexley->id}", false)
        ->instance()
        ->candidates();

    expect(collect($candidates)->pluck('member.name')->sort()->values()->all())->toBe(['Ashwood', 'Bexley']);
});

test('candidates run from never conducted through to the most recent turn', function () {
    [$user, $team] = planUserWithTeam();

    $never = planMember($team, 'Never', 1_000);
    $recent = planMember($team, 'Recent', 1_000);
    $old = planMember($team, 'Old', 1_000);

    ConductorAssignment::factory()->for($team)->for($recent)->on(CarbonImmutable::parse('2025-01-09'))->create();
    ConductorAssignment::factory()->for($team)->for($old)->on(CarbonImmutable::parse('2024-11-02'))->create();

    $candidates = Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->instance()
        ->candidates();

    expect(collect($candidates)->pluck('member.name')->all())->toBe(['Never', 'Old', 'Recent'])
        ->and($candidates[0]['member']->id)->toBe($never->id)
        ->and($candidates[0]['lastConductedOn'])->toBeNull()
        ->and($candidates[2]['lastConductedOn']->toDateString())->toBe('2025-01-09');
});

test('reaching the assign step pre-fills the six days from the candidate list', function () {
    [$user, $team] = planUserWithTeam();

    $members = collect(['A', 'B', 'C', 'D', 'E', 'F', 'G'])
        ->map(fn (string $name): Member => planMember($team, $name, 1_000));

    $component = Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->call('nextStep')  // -> candidates
        ->call('nextStep'); // -> assign

    $component->assertSet('step', 4);

    // Nobody has conducted, so the order falls through to name and the seventh
    // member is left over — there are only six days to fill.
    expect($component->get('selections'))->toBe([
        '2025-01-13' => $members[0]->id,
        '2025-01-14' => $members[1]->id,
        '2025-01-15' => $members[2]->id,
        '2025-01-16' => $members[3]->id,
        '2025-01-17' => $members[4]->id,
        '2025-01-18' => $members[5]->id,
    ]);
});

test('days are left unassigned when there are fewer candidates than days', function () {
    [$user, $team] = planUserWithTeam();

    $ashwood = planMember($team, 'Ashwood', 1_000);

    $component = Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->call('nextStep')
        ->call('nextStep');

    expect($component->get('selections'))->toBe([
        '2025-01-13' => $ashwood->id,
        '2025-01-14' => null,
        '2025-01-15' => null,
        '2025-01-16' => null,
        '2025-01-17' => null,
        '2025-01-18' => null,
    ]);
});

test('stepping back and forward keeps picks that are still candidates', function () {
    [$user, $team] = planUserWithTeam(desertStorm: true);

    $members = collect(['A', 'B', 'C', 'D', 'E', 'F', 'G'])
        ->map(fn (string $name): Member => planMember($team, $name, 1_000));

    $component = Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->call('nextStep')  // -> desert storm
        ->call('nextStep')  // -> candidates
        ->call('nextStep')  // -> assign
        ->set('selections.2025-01-13', $members[6]->id)
        ->call('previousStep')
        ->call('nextStep');

    expect($component->get('selections')['2025-01-13'])->toBe($members[6]->id);
});

test('a member unticked after being picked is dropped from the day', function () {
    [$user, $team] = planUserWithTeam(desertStorm: true);

    $members = collect(['A', 'B', 'C', 'D', 'E', 'F', 'G'])
        ->map(fn (string $name): Member => planMember($team, $name, 1_000));

    $component = Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->call('nextStep')
        ->call('nextStep')
        ->call('nextStep');

    expect($component->get('selections')['2025-01-13'])->toBe($members[0]->id);

    $component->call('previousStep')  // -> candidates
        ->call('previousStep')        // -> desert storm
        ->set("desertStorm.{$members[0]->id}", false)
        ->call('nextStep')
        ->call('nextStep');

    // The dropped member's day is refilled from whoever is left over.
    expect($component->get('selections')['2025-01-13'])->toBe($members[6]->id)
        ->and(collect($component->get('selections'))->filter()->contains($members[0]->id))->toBeFalse();
});

test('saving writes the whole week with mvp on the sunday', function () {
    [$user, $team] = planUserWithTeam();

    $members = collect(['A', 'B', 'C', 'D', 'E', 'F', 'G'])
        ->map(fn (string $name): Member => planMember($team, $name, 1_000));

    Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->call('nextStep')
        ->call('nextStep')
        ->set('mvpMemberId', $members[6]->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('conductors.index'));

    expect($team->conductorAssignments()->count())->toBe(7);

    $this->assertDatabaseHas('conductor_assignments', [
        'team_id' => $team->id,
        'member_id' => $members[6]->id,
        'assigned_on' => '2025-01-12 00:00:00',
        'is_mvp' => true,
    ]);

    $this->assertDatabaseHas('conductor_assignments', [
        'team_id' => $team->id,
        'member_id' => $members[0]->id,
        'assigned_on' => '2025-01-13 00:00:00',
        'is_mvp' => false,
    ]);

    expect($team->conductorAssignments()->where('is_mvp', true)->count())->toBe(1);
});

test('unassigned days produce no records', function () {
    [$user, $team] = planUserWithTeam(trainVsRequirement: 1);

    $ashwood = planMember($team, 'Ashwood', 1_000);
    $bexley = planMember($team, 'Bexley'); // no score, so eligible for MVP but not a candidate

    Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->call('nextStep')
        ->call('nextStep')
        ->set('mvpMemberId', $bexley->id)
        ->call('save')
        ->assertHasNoErrors();

    // The MVP Sunday plus the one day Ashwood could fill.
    expect($team->conductorAssignments()->count())->toBe(2)
        ->and($team->conductorAssignments()->where('member_id', $ashwood->id)->value('assigned_on')->toDateString())
        ->toBe('2025-01-13');
});

test('re-planning a week amends the days already on record', function () {
    [$user, $team] = planUserWithTeam(trainVsRequirement: 1);

    $ashwood = planMember($team, 'Ashwood', 1_000);
    $bexley = planMember($team, 'Bexley'); // no score, so eligible for MVP but not a candidate

    $existing = ConductorAssignment::factory()
        ->for($team)
        ->for($bexley)
        ->on(CarbonImmutable::parse('2025-01-13'))
        ->create();

    Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->call('nextStep')
        ->call('nextStep')
        ->assertSeeHtml('data-test="plan-existing-warning"')
        ->set('selections.2025-01-13', $ashwood->id)
        ->set('mvpMemberId', $bexley->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($existing->fresh()->member_id)->toBe($ashwood->id)
        ->and($team->conductorAssignments()->whereDate('assigned_on', '2025-01-13')->count())->toBe(1);
});

test('a member cannot be scheduled twice in the same week', function () {
    [$user, $team] = planUserWithTeam();

    $members = collect(['A', 'B', 'C', 'D', 'E', 'F'])
        ->map(fn (string $name): Member => planMember($team, $name, 1_000));

    Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->call('nextStep')
        ->call('nextStep')
        ->set('selections.2025-01-14', $members[0]->id)
        ->set('mvpMemberId', $members[5]->id)
        ->call('save')
        ->assertHasErrors('selections');

    expect($team->conductorAssignments()->count())->toBe(0);
});

test('the mvp cannot also conduct another day that week', function () {
    [$user, $team] = planUserWithTeam();

    $members = collect(['A', 'B', 'C', 'D', 'E', 'F'])
        ->map(fn (string $name): Member => planMember($team, $name, 1_000));

    Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->call('nextStep')
        ->call('nextStep')
        ->set('mvpMemberId', $members[0]->id)
        ->call('save')
        ->assertHasErrors('selections');
});

test('an mvp must be picked before the week can be saved', function () {
    [$user, $team] = planUserWithTeam();

    planMember($team, 'Ashwood', 1_000);

    Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->call('nextStep')
        ->call('nextStep')
        ->call('save')
        ->assertHasErrors('mvpMemberId');

    expect($team->conductorAssignments()->count())->toBe(0);
});

test('a member from another team cannot be scheduled', function () {
    [$user, $team] = planUserWithTeam();

    planMember($team, 'Ashwood', 1_000);
    $outsider = Member::factory()->create(['name' => 'Outsider']);

    Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->call('nextStep')
        ->call('nextStep')
        ->set('mvpMemberId', $outsider->id)
        ->call('save')
        ->assertHasErrors('mvpMemberId');

    expect($team->conductorAssignments()->count())->toBe(0);
});

test('a day already assigned in the planned week is flagged before saving', function () {
    [$user, $team] = planUserWithTeam();

    $ashwood = planMember($team, 'Ashwood', 1_000);

    ConductorAssignment::factory()->for($team)->for($ashwood)->on(CarbonImmutable::parse('2025-01-16'))->create();

    Livewire::actingAs($user)
        ->test('pages::conductors.plan')
        ->call('nextStep')
        ->call('nextStep')
        ->assertSeeHtml('data-test="plan-existing-warning"');
});

test('the conductors page links to the planner', function () {
    [$user] = planUserWithTeam();

    Livewire::actingAs($user)
        ->test('pages::conductors.index')
        ->assertSeeHtml('data-test="conductor-plan-button"');
});
