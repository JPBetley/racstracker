<?php

use App\Enums\MemberPosition;
use App\Models\Member;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('guests are redirected from the members page', function () {
    User::factory()->create();

    $this->get(route('members.index'))->assertRedirect(route('login'));
});

test('members page loads for an authenticated team member', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('members.index'))
        ->assertOk();
});

test('a member can be added to the roster', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    Livewire::actingAs($user)
        ->test('pages::members.index')
        ->set('name', 'Tatsumori')
        ->set('position', 'R3')
        ->call('saveMember')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('members', [
        'team_id' => $team->id,
        'name' => 'Tatsumori',
        'position' => 'R3',
    ]);
});

test('member names must be unique within a team', function () {
    $user = User::factory()->create();
    Member::factory()->for($user->currentTeam)->create(['name' => 'Tatsumori']);

    Livewire::actingAs($user)
        ->test('pages::members.index')
        ->set('name', 'Tatsumori')
        ->set('position', 'R2')
        ->call('saveMember')
        ->assertHasErrors('name');
});

test('the same member name is allowed across different teams', function () {
    $user = User::factory()->create();
    Member::factory()->for(Team::factory())->create(['name' => 'Tatsumori']);

    Livewire::actingAs($user)
        ->test('pages::members.index')
        ->set('name', 'Tatsumori')
        ->set('position', 'R3')
        ->call('saveMember')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('members', [
        'team_id' => $user->currentTeam->id,
        'name' => 'Tatsumori',
    ]);
});

test('only one R5 is allowed per team', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    Member::factory()->for($team)->r5()->create();

    Livewire::actingAs($user)
        ->test('pages::members.index')
        ->set('name', 'Pretender')
        ->set('position', 'R5')
        ->call('saveMember')
        ->assertHasErrors('position');

    expect($team->roster()->where('position', 'R5')->count())->toBe(1);
});

test('no more than ten R4 are allowed per team', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    Member::factory()->for($team)->r4()->count(10)->create();

    $component = Livewire::actingAs($user)->test('pages::members.index');

    $component->set('name', 'Eleventh Officer')
        ->set('position', 'R4')
        ->call('saveMember')
        ->assertHasErrors('position');

    // A different (uncapped) position still succeeds.
    $component->set('name', 'Foot Soldier')
        ->set('position', 'R3')
        ->call('saveMember')
        ->assertHasNoErrors();
});

test('the roster is ordered by position from R5 down to R1', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    Member::factory()->for($team)->create(['name' => 'Grunt', 'position' => MemberPosition::R1]);
    Member::factory()->for($team)->r5()->create(['name' => 'Leader']);
    Member::factory()->for($team)->create(['name' => 'Captain', 'position' => MemberPosition::R3]);
    Member::factory()->for($team)->r4()->create(['name' => 'Officer']);
    Member::factory()->for($team)->create(['name' => 'Sergeant', 'position' => MemberPosition::R2]);

    // Rows render top-to-bottom in roster order, so R5 (Leader) through R1 (Grunt).
    Livewire::actingAs($user)
        ->test('pages::members.index')
        ->assertSeeInOrder(['Leader', 'Officer', 'Captain', 'Sergeant', 'Grunt']);
});

test('a member can be updated', function () {
    $user = User::factory()->create();
    $member = Member::factory()->for($user->currentTeam)->create([
        'name' => 'Old Name',
        'position' => MemberPosition::R3,
    ]);

    Livewire::actingAs($user)
        ->test('pages::members.index')
        ->call('editMember', $member->id)
        ->set('name', 'New Name')
        ->set('position', 'R2')
        ->call('saveMember')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('members', [
        'id' => $member->id,
        'name' => 'New Name',
        'position' => 'R2',
    ]);
});

test('renaming a member records both names as aliases', function () {
    $user = User::factory()->create();
    $member = Member::factory()->for($user->currentTeam)->create(['name' => 'Old Name']);

    Livewire::actingAs($user)
        ->test('pages::members.index')
        ->call('editMember', $member->id)
        ->set('name', 'New Name')
        ->call('saveMember')
        ->assertHasNoErrors();

    expect($member->aliases()->pluck('name')->sort()->values()->all())
        ->toBe(['New Name', 'Old Name']);
});

test('editing the only R5 in place does not trip the cap', function () {
    $user = User::factory()->create();
    $member = Member::factory()->for($user->currentTeam)->r5()->create(['name' => 'Yeti']);

    Livewire::actingAs($user)
        ->test('pages::members.index')
        ->call('editMember', $member->id)
        ->set('name', 'Mr Yeti')
        ->call('saveMember')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('members', [
        'id' => $member->id,
        'name' => 'Mr Yeti',
        'position' => 'R5',
    ]);
});

test('a member can be deleted', function () {
    $user = User::factory()->create();
    $member = Member::factory()->for($user->currentTeam)->create();

    Livewire::actingAs($user)
        ->test('pages::members.index')
        ->call('deleteMember', $member->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('members', ['id' => $member->id]);
});

test('users cannot view the members page of a team they do not belong to', function () {
    $user = User::factory()->create();
    $otherTeam = Team::factory()->create();

    $this->actingAs($user)
        ->get(route('members.index', ['current_team' => $otherTeam->slug]))
        ->assertForbidden();
});

test('a member from another team cannot be deleted', function () {
    $user = User::factory()->create();
    $otherMember = Member::factory()->for(Team::factory())->create();

    $component = Livewire::actingAs($user)->test('pages::members.index');

    // The roster is scoped to the user's current team, so a foreign id is not found.
    expect(fn () => $component->call('deleteMember', $otherMember->id))
        ->toThrow(ModelNotFoundException::class);

    $this->assertDatabaseHas('members', ['id' => $otherMember->id]);
});
