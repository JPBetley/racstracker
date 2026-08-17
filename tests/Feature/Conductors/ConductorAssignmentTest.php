<?php

use App\Models\ConductorAssignment;
use App\Models\Member;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('guests are redirected from the conductors page', function () {
    User::factory()->create();

    $this->get(route('conductors.index'))->assertRedirect(route('login'));
});

test('conductors page loads for an authenticated team member', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('conductors.index'))
        ->assertOk();
});

test('a conductor can be assigned to a day', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $member = Member::factory()->for($team)->create();

    Livewire::actingAs($user)
        ->test('pages::conductors.index')
        ->set('memberId', $member->id)
        ->set('assignedOn', '2026-08-16')
        ->call('saveAssignment')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('conductor_assignments', [
        'team_id' => $team->id,
        'member_id' => $member->id,
        'assigned_on' => '2026-08-16 00:00:00',
    ]);
});

test('a day can only have one conductor per team', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    ConductorAssignment::factory()->for($team)->on(CarbonImmutable::parse('2026-08-16'))->create();
    $member = Member::factory()->for($team)->create();

    Livewire::actingAs($user)
        ->test('pages::conductors.index')
        ->set('memberId', $member->id)
        ->set('assignedOn', '2026-08-16')
        ->call('saveAssignment')
        ->assertHasErrors('assignedOn');
});

test('the same day can be assigned in different teams', function () {
    $user = User::factory()->create();
    ConductorAssignment::factory()->for(Team::factory())->on(CarbonImmutable::parse('2026-08-16'))->create();
    $member = Member::factory()->for($user->currentTeam)->create();

    Livewire::actingAs($user)
        ->test('pages::conductors.index')
        ->set('memberId', $member->id)
        ->set('assignedOn', '2026-08-16')
        ->call('saveAssignment')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('conductor_assignments', [
        'team_id' => $user->currentTeam->id,
        'assigned_on' => '2026-08-16 00:00:00',
    ]);
});

test('a member from another team cannot be assigned', function () {
    $user = User::factory()->create();
    $outsider = Member::factory()->for(Team::factory())->create();

    Livewire::actingAs($user)
        ->test('pages::conductors.index')
        ->set('memberId', $outsider->id)
        ->set('assignedOn', '2026-08-16')
        ->call('saveAssignment')
        ->assertHasErrors('memberId');

    $this->assertDatabaseCount('conductor_assignments', 0);
});

test('an assignment can be edited', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $assignment = ConductorAssignment::factory()->for($team)->on(CarbonImmutable::parse('2026-08-16'))->create();
    $replacement = Member::factory()->for($team)->create();

    Livewire::actingAs($user)
        ->test('pages::conductors.index')
        ->call('editAssignment', $assignment->id)
        ->assertSet('memberId', $assignment->member_id)
        ->assertSet('assignedOn', '2026-08-16')
        ->set('memberId', $replacement->id)
        ->set('assignedOn', '2026-08-17')
        ->call('saveAssignment')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('conductor_assignments', [
        'id' => $assignment->id,
        'member_id' => $replacement->id,
        'assigned_on' => '2026-08-17 00:00:00',
    ]);
});

test('an assignment keeps its own date when edited', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $assignment = ConductorAssignment::factory()->for($team)->on(CarbonImmutable::parse('2026-08-16'))->create();
    $replacement = Member::factory()->for($team)->create();

    Livewire::actingAs($user)
        ->test('pages::conductors.index')
        ->call('editAssignment', $assignment->id)
        ->set('memberId', $replacement->id)
        ->call('saveAssignment')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('conductor_assignments', [
        'id' => $assignment->id,
        'member_id' => $replacement->id,
        'assigned_on' => '2026-08-16 00:00:00',
    ]);
});

test('an assignment can be deleted', function () {
    $user = User::factory()->create();
    $assignment = ConductorAssignment::factory()->for($user->currentTeam)->create();

    Livewire::actingAs($user)
        ->test('pages::conductors.index')
        ->call('deleteAssignment', $assignment->id);

    $this->assertDatabaseMissing('conductor_assignments', ['id' => $assignment->id]);
});

test('the history lists the newest assignment first with its day of the week', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    ConductorAssignment::factory()
        ->for($team)
        ->for(Member::factory()->for($team)->create(['name' => 'Earlier']))
        ->on(CarbonImmutable::parse('2026-08-14'))
        ->create();

    ConductorAssignment::factory()
        ->for($team)
        ->for(Member::factory()->for($team)->create(['name' => 'Later']))
        ->on(CarbonImmutable::parse('2026-08-16'))
        ->create();

    Livewire::actingAs($user)
        ->test('pages::conductors.index')
        ->assertSeeInOrder(['Later', 'Aug 16, 2026', 'Sunday', 'Earlier', 'Aug 14, 2026', 'Friday']);
});

test('another team\'s assignment cannot be edited or deleted', function () {
    $user = User::factory()->create();
    $foreign = ConductorAssignment::factory()->for(Team::factory())->create();

    $component = Livewire::actingAs($user)->test('pages::conductors.index');

    expect(fn () => $component->call('editAssignment', $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    expect(fn () => $component->call('deleteAssignment', $foreign->id))
        ->toThrow(ModelNotFoundException::class);
});
