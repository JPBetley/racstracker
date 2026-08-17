<?php

use App\Models\ConductorAssignment;
use App\Models\Member;
use App\Models\MemberAlias;
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

test('the conductor combobox can be searched by a member\'s former names', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $member = Member::factory()->for($team)->create(['name' => 'Ravager']);
    MemberAlias::factory()->for($member)->create(['name' => 'OldRavager']);

    Livewire::actingAs($user)
        ->test('pages::conductors.index')
        ->assertSee('autocomplete="strict"', escape: false)
        ->assertSee('Ravager')
        ->assertSee('<span hidden>OldRavager</span>', escape: false);
});

test('a former name identical to the current name is not repeated in the combobox', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $member = Member::factory()->for($team)->create(['name' => 'Ravager']);
    MemberAlias::factory()->for($member)->create(['name' => 'Ravager']);

    $html = Livewire::actingAs($user)->test('pages::conductors.index')->html();

    expect(substr_count($html, 'Ravager'))->toBe(1);
});

test('a conductor can be flagged as MVP when assigned', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $member = Member::factory()->for($team)->create();

    Livewire::actingAs($user)
        ->test('pages::conductors.index')
        ->set('memberId', $member->id)
        ->set('assignedOn', '2026-08-16')
        ->set('isMvp', true)
        ->call('saveAssignment')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('conductor_assignments', [
        'member_id' => $member->id,
        'is_mvp' => true,
    ]);
});

test('an assignment is not MVP by default', function () {
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
        'member_id' => $member->id,
        'is_mvp' => false,
    ]);
});

test('editing an assignment loads and keeps its MVP flag', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $assignment = ConductorAssignment::factory()->for($team)->mvp()->create();

    Livewire::actingAs($user)
        ->test('pages::conductors.index')
        ->call('editAssignment', $assignment->id)
        ->assertSet('isMvp', true)
        ->call('saveAssignment')
        ->assertHasNoErrors();

    expect($assignment->fresh()->is_mvp)->toBeTrue();
});

test('the MVP flag can be cleared by editing', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $assignment = ConductorAssignment::factory()->for($team)->mvp()->create();

    Livewire::actingAs($user)
        ->test('pages::conductors.index')
        ->call('editAssignment', $assignment->id)
        ->set('isMvp', false)
        ->call('saveAssignment')
        ->assertHasNoErrors();

    expect($assignment->fresh()->is_mvp)->toBeFalse();
});

test('the MVP flag does not leak from an edited assignment into the next one', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $mvp = ConductorAssignment::factory()->for($team)->mvp()->on(CarbonImmutable::parse('2026-08-16'))->create();
    $member = Member::factory()->for($team)->create();

    Livewire::actingAs($user)
        ->test('pages::conductors.index')
        ->call('editAssignment', $mvp->id)
        ->call('saveAssignment')
        ->call('addAssignment')
        ->assertSet('isMvp', false)
        ->set('memberId', $member->id)
        ->set('assignedOn', '2026-08-17')
        ->call('saveAssignment')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('conductor_assignments', [
        'member_id' => $member->id,
        'is_mvp' => false,
    ]);
});

test('an MVP badge is shown on the row and a plain assignment gets none', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    ConductorAssignment::factory()->for($team)->mvp()->on(CarbonImmutable::parse('2026-08-16'))->create();

    Livewire::actingAs($user)
        ->test('pages::conductors.index')
        ->assertSeeHtml('data-test="conductor-mvp-badge"')
        ->assertSee('MVP');

    ConductorAssignment::query()->update(['is_mvp' => false]);

    Livewire::actingAs($user)
        ->test('pages::conductors.index')
        ->assertDontSeeHtml('data-test="conductor-mvp-badge"');
});
