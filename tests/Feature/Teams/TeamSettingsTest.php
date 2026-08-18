<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from the team settings page', function () {
    User::factory()->create();

    $this->get(route('team-settings.edit'))->assertRedirect(route('login'));
});

test('a new team starts with no requirements', function () {
    $team = Team::factory()->create();

    expect($team->vs_minimum)->toBe(0)
        ->and($team->train_vs_requirement)->toBe(0)
        ->and($team->train_desert_storm_requirement)->toBeFalse();
});

test('the team settings page loads for a team member and shows the current values', function () {
    $user = User::factory()->create();

    $user->currentTeam->update([
        'vs_minimum' => 1200000,
        'train_vs_requirement' => 900000,
        'train_desert_storm_requirement' => true,
    ]);

    $this->actingAs($user)
        ->get(route('team-settings.edit'))
        ->assertOk();

    Livewire::actingAs($user)
        ->test('pages::teams.settings')
        ->assertSet('vsMinimum', '1200000')
        ->assertSet('trainVsRequirement', '900000')
        ->assertSet('trainDesertStormRequirement', true);
});

test('an owner can save the team settings', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    Livewire::actingAs($user)
        ->test('pages::teams.settings')
        ->set('vsMinimum', 1500000)
        ->set('trainVsRequirement', 750000)
        ->set('trainDesertStormRequirement', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('teams', [
        'id' => $team->id,
        'vs_minimum' => 1500000,
        'train_vs_requirement' => 750000,
        'train_desert_storm_requirement' => true,
    ]);
});

test('the desert storm requirement can be switched back off', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['train_desert_storm_requirement' => true]);

    Livewire::actingAs($user)
        ->test('pages::teams.settings')
        ->set('trainDesertStormRequirement', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($team->refresh()->train_desert_storm_requirement)->toBeFalse();
});

test('requirements below zero are rejected', function (string $property) {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    Livewire::actingAs($user)
        ->test('pages::teams.settings')
        ->set($property, -1)
        ->call('save')
        ->assertHasErrors($property);

    expect($team->refresh()->vs_minimum)->toBe(0)
        ->and($team->train_vs_requirement)->toBe(0);
})->with(['vsMinimum', 'trainVsRequirement']);

test('clearing a requirement stores it as no requirement', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['vs_minimum' => 1000]);

    Livewire::actingAs($user)
        ->test('pages::teams.settings')
        ->set('vsMinimum', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($team->refresh()->vs_minimum)->toBe(0);
});

test('a member cannot change the team settings', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    Livewire::actingAs($member)
        ->test('pages::teams.settings')
        ->set('vsMinimum', 1000)
        ->call('save')
        ->assertForbidden();

    expect($team->refresh()->vs_minimum)->toBe(0);
});

test('a member sees the settings read only', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    Livewire::actingAs($member)
        ->test('pages::teams.settings')
        ->assertDontSeeHtml('data-test="vs-minimum-input"')
        ->assertDontSeeHtml('data-test="team-settings-save-button"')
        ->assertSeeHtml('data-test="vs-minimum-value"');
});

test('masked requirements are stored without their separators', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    Livewire::actingAs($user)
        ->test('pages::teams.settings')
        ->set('vsMinimum', '1,200,000')
        ->set('trainVsRequirement', '70,000')
        ->call('save')
        ->assertHasNoErrors();

    expect($team->refresh()->vs_minimum)->toBe(1200000)
        ->and($team->train_vs_requirement)->toBe(70000);
});
