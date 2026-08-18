<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

/**
 * Build a team owned by a fresh user, ready to have people added to it.
 *
 * @return array{0: User, 1: Team}
 */
function teamAwaitingMembers(): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    return [$owner, $team];
}

test('an owner can add a verified user found by the lookup', function () {
    [$owner, $team] = teamAwaitingMembers();
    $outsider = User::factory()->create(['name' => 'Bexley Hart']);

    $this->actingAs($owner);

    Livewire::test('pages::teams.add-member-modal', ['team' => $team])
        ->set('search', 'Bexley')
        ->assertSee('Bexley Hart')
        ->set('role', TeamRole::Admin->value)
        ->call('addMember', $outsider->id)
        ->assertHasNoErrors();

    $membership = $team->memberships()->where('user_id', $outsider->id)->first();

    expect($membership)->not->toBeNull()
        ->and($membership->role)->toBe(TeamRole::Admin);
});

test('the lookup matches on email address as well as name', function () {
    [$owner, $team] = teamAwaitingMembers();
    User::factory()->create(['name' => 'Bexley Hart', 'email' => 'corvid@example.com']);

    $this->actingAs($owner);

    Livewire::test('pages::teams.add-member-modal', ['team' => $team])
        ->set('search', 'corvid@')
        ->assertSee('Bexley Hart');
});

test('the lookup ignores users who have not verified their email', function () {
    [$owner, $team] = teamAwaitingMembers();
    User::factory()->unverified()->create(['name' => 'Unverified Ursula']);

    $this->actingAs($owner);

    Livewire::test('pages::teams.add-member-modal', ['team' => $team])
        ->set('search', 'Ursula')
        ->assertDontSee('Unverified Ursula')
        ->assertSee('No verified users match that search');
});

test('the lookup ignores people who already belong to the team', function () {
    [$owner, $team] = teamAwaitingMembers();
    $existing = User::factory()->create(['name' => 'Already Aboard']);
    $team->members()->attach($existing, ['role' => TeamRole::Member->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.add-member-modal', ['team' => $team])
        ->set('search', 'Already')
        ->assertDontSee('Already Aboard');
});

test('a search too short to be a lookup returns nothing', function () {
    [$owner, $team] = teamAwaitingMembers();
    User::factory()->create(['name' => 'Bexley Hart']);

    $this->actingAs($owner);

    Livewire::test('pages::teams.add-member-modal', ['team' => $team])
        ->set('search', 'B')
        ->assertDontSee('Bexley Hart')
        ->assertDontSee('No verified users match that search');
});

test('an unverified user cannot be added by posting their id straight back', function () {
    [$owner, $team] = teamAwaitingMembers();
    $unverified = User::factory()->unverified()->create();

    $this->actingAs($owner);

    Livewire::test('pages::teams.add-member-modal', ['team' => $team])
        ->call('addMember', $unverified->id);

    expect($team->memberships()->where('user_id', $unverified->id)->exists())->toBeFalse();
});

test('adding somebody twice leaves their existing role alone', function () {
    [$owner, $team] = teamAwaitingMembers();
    $existing = User::factory()->create();
    $team->members()->attach($existing, ['role' => TeamRole::Member->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.add-member-modal', ['team' => $team])
        ->set('role', TeamRole::Admin->value)
        ->call('addMember', $existing->id);

    expect($team->memberships()->where('user_id', $existing->id)->count())->toBe(1)
        ->and($team->memberships()->where('user_id', $existing->id)->first()->role)->toBe(TeamRole::Member);
});

test('a member cannot be added as an owner', function () {
    [$owner, $team] = teamAwaitingMembers();
    $outsider = User::factory()->create();

    $this->actingAs($owner);

    Livewire::test('pages::teams.add-member-modal', ['team' => $team])
        ->set('role', TeamRole::Owner->value)
        ->call('addMember', $outsider->id)
        ->assertHasErrors('role');

    expect($team->memberships()->where('user_id', $outsider->id)->exists())->toBeFalse();
});

test('an admin cannot add members', function () {
    [$owner, $team] = teamAwaitingMembers();
    $admin = User::factory()->create();
    $outsider = User::factory()->create();

    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin);

    Livewire::test('pages::teams.add-member-modal', ['team' => $team])
        ->call('addMember', $outsider->id)
        ->assertForbidden();

    expect($team->memberships()->where('user_id', $outsider->id)->exists())->toBeFalse();
});

test('only owners are offered the add existing member button', function () {
    [$owner, $team] = teamAwaitingMembers();
    $admin = User::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    Livewire::actingAs($owner)
        ->test('pages::teams.edit', ['team' => $team])
        ->assertSeeHtml('data-test="add-member-button"');

    Livewire::actingAs($admin)
        ->test('pages::teams.edit', ['team' => $team])
        ->assertDontSeeHtml('data-test="add-member-button"')
        ->assertSeeHtml('data-test="invite-member-button"');
});
