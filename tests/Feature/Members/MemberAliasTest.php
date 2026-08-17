<?php

use App\Models\Member;
use App\Models\MemberAlias;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('the alias modal lists a member past names and hides the current one', function () {
    $user = User::factory()->create();
    $member = Member::factory()->for($user->currentTeam)->create(['name' => 'Tatsumori']);

    // The roster sync records the current name as an alias alongside the old ones.
    MemberAlias::factory()->for($member)->create(['name' => 'Tatsumori']);
    MemberAlias::factory()->for($member)->create(['name' => 'Tatsu']);

    $component = Livewire::actingAs($user)
        ->test('pages::members.index')
        ->call('manageAliases', $member->id);

    expect($component->get('formerNames')->pluck('name')->all())->toBe(['Tatsu']);
});

test('a past name can be added', function () {
    $user = User::factory()->create();
    $member = Member::factory()->for($user->currentTeam)->create(['name' => 'Tatsumori']);

    Livewire::actingAs($user)
        ->test('pages::members.index')
        ->call('manageAliases', $member->id)
        ->set('newAliasName', '  Tatsu  ')
        ->call('addAlias')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('member_aliases', [
        'member_id' => $member->id,
        'name' => 'Tatsu',
    ]);
});

test('a name already recorded for the member is rejected', function () {
    $user = User::factory()->create();
    $member = Member::factory()->for($user->currentTeam)->create(['name' => 'Tatsumori']);
    MemberAlias::factory()->for($member)->create(['name' => 'Tatsu']);

    Livewire::actingAs($user)
        ->test('pages::members.index')
        ->call('manageAliases', $member->id)
        ->set('newAliasName', 'Tatsu')
        ->call('addAlias')
        ->assertHasErrors('newAliasName');

    expect($member->aliases()->where('name', 'Tatsu')->count())->toBe(1);
});

test('the same past name is allowed on two different members', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $other = Member::factory()->for($team)->create(['name' => 'Ravager']);
    MemberAlias::factory()->for($other)->create(['name' => 'Ravvy']);
    $member = Member::factory()->for($team)->create(['name' => 'Tatsumori']);

    Livewire::actingAs($user)
        ->test('pages::members.index')
        ->call('manageAliases', $member->id)
        ->set('newAliasName', 'Ravvy')
        ->call('addAlias')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('member_aliases', [
        'member_id' => $member->id,
        'name' => 'Ravvy',
    ]);
});

test('the member current name cannot be added as a past name', function () {
    $user = User::factory()->create();
    $member = Member::factory()->for($user->currentTeam)->create(['name' => 'Tatsumori']);

    Livewire::actingAs($user)
        ->test('pages::members.index')
        ->call('manageAliases', $member->id)
        ->set('newAliasName', 'Tatsumori')
        ->call('addAlias')
        ->assertHasErrors('newAliasName');

    expect($member->aliases()->count())->toBe(0);
});

test('a past name can be renamed', function () {
    $user = User::factory()->create();
    $member = Member::factory()->for($user->currentTeam)->create(['name' => 'Tatsumori']);
    $alias = MemberAlias::factory()->for($member)->create(['name' => 'Tatsu']);

    Livewire::actingAs($user)
        ->test('pages::members.index')
        ->call('manageAliases', $member->id)
        ->call('editAlias', $alias->id)
        ->assertSet('editingAliasName', 'Tatsu')
        ->set('editingAliasName', 'Tatsumori_old')
        ->call('updateAlias')
        ->assertHasNoErrors()
        ->assertSet('editingAliasId', null);

    $this->assertDatabaseHas('member_aliases', [
        'id' => $alias->id,
        'name' => 'Tatsumori_old',
    ]);
});

test('renaming a past name to one already recorded is rejected', function () {
    $user = User::factory()->create();
    $member = Member::factory()->for($user->currentTeam)->create(['name' => 'Tatsumori']);
    $alias = MemberAlias::factory()->for($member)->create(['name' => 'Tatsu']);
    MemberAlias::factory()->for($member)->create(['name' => 'Tats']);

    Livewire::actingAs($user)
        ->test('pages::members.index')
        ->call('manageAliases', $member->id)
        ->call('editAlias', $alias->id)
        ->set('editingAliasName', 'Tats')
        ->call('updateAlias')
        ->assertHasErrors('editingAliasName');

    $this->assertDatabaseHas('member_aliases', ['id' => $alias->id, 'name' => 'Tatsu']);
});

test('a past name can be forgotten', function () {
    $user = User::factory()->create();
    $member = Member::factory()->for($user->currentTeam)->create(['name' => 'Tatsumori']);
    $alias = MemberAlias::factory()->for($member)->create(['name' => 'Tatsu']);

    Livewire::actingAs($user)
        ->test('pages::members.index')
        ->call('manageAliases', $member->id)
        ->call('deleteAlias', $alias->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('member_aliases', ['id' => $alias->id]);
});

test('the alias matching the current name cannot be renamed or forgotten', function () {
    $user = User::factory()->create();
    $member = Member::factory()->for($user->currentTeam)->create(['name' => 'Tatsumori']);
    $alias = MemberAlias::factory()->for($member)->create(['name' => 'Tatsumori']);

    $component = Livewire::actingAs($user)
        ->test('pages::members.index')
        ->call('manageAliases', $member->id);

    $component->call('deleteAlias', $alias->id)->assertHasErrors('alias');

    $component->call('editAlias', $alias->id)
        ->set('editingAliasName', 'Something else')
        ->call('updateAlias')
        ->assertHasErrors('alias');

    $this->assertDatabaseHas('member_aliases', ['id' => $alias->id, 'name' => 'Tatsumori']);
});

test('aliases of a member from another team cannot be managed', function () {
    $user = User::factory()->create();
    $otherMember = Member::factory()->for(Team::factory())->create();
    $otherAlias = MemberAlias::factory()->for($otherMember)->create(['name' => 'Intruder']);

    $component = Livewire::actingAs($user)->test('pages::members.index');

    // The roster is scoped to the user's current team, so a foreign id is not found.
    expect(fn () => $component->call('manageAliases', $otherMember->id))
        ->toThrow(ModelNotFoundException::class);

    $ownMember = Member::factory()->for($user->currentTeam)->create();
    $component->call('manageAliases', $ownMember->id);

    // Nor can a foreign alias id be reached through a member the user does own.
    expect(fn () => $component->call('deleteAlias', $otherAlias->id))
        ->toThrow(ModelNotFoundException::class);

    $this->assertDatabaseHas('member_aliases', ['id' => $otherAlias->id]);
});
