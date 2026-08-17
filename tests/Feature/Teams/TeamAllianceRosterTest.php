<?php

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\TeamRole;
use App\Jobs\Imports\SyncAllianceMembers;
use App\Models\Import;
use App\Models\Member;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * A valid 32-character hex alliance ID.
 */
function allianceId(string $fill = 'a'): string
{
    return str_repeat($fill, 32);
}

/**
 * Create a team the given user owns, so team updates are authorized.
 */
function ownedTeam(User $user, array $attributes = []): Team
{
    $team = Team::factory()->create($attributes);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    return $team;
}

beforeEach(function () {
    config()->set('services.lastwar.key', 'test-key');
    config()->set('services.lastwar.alliance_id', null);
});

test('the alliance section renders for an owner but not for a member', function () {
    $owner = User::factory()->create();
    $team = ownedTeam($owner, ['alliance_id' => allianceId()]);

    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    Livewire::actingAs($owner)
        ->test('pages::teams.edit', ['team' => $team])
        ->assertSeeHtml('data-test="alliance-id-input"')
        ->assertSeeHtml('data-test="alliance-sync-button"');

    Livewire::actingAs($member)
        ->test('pages::teams.edit', ['team' => $team])
        ->assertDontSeeHtml('data-test="alliance-id-input"')
        ->assertDontSeeHtml('data-test="alliance-sync-button"');
});

test('an owner can save an alliance id', function () {
    $user = User::factory()->create();
    $team = ownedTeam($user, ['alliance_id' => null]);

    Livewire::actingAs($user)
        ->test('pages::teams.edit', ['team' => $team])
        ->set('allianceId', allianceId())
        ->call('updateAlliance')
        ->assertHasNoErrors();

    expect($team->refresh()->alliance_id)->toBe(allianceId());
});

test('the alliance id is normalised to lowercase and trimmed', function () {
    $user = User::factory()->create();
    $team = ownedTeam($user, ['alliance_id' => null]);

    Livewire::actingAs($user)
        ->test('pages::teams.edit', ['team' => $team])
        ->set('allianceId', '  '.allianceId('A').'  ')
        ->call('updateAlliance')
        ->assertHasNoErrors();

    expect($team->refresh()->alliance_id)->toBe(allianceId());
});

test('an alliance id that is not 32 hex characters is rejected', function (string $value) {
    $user = User::factory()->create();
    $team = ownedTeam($user, ['alliance_id' => null]);

    Livewire::actingAs($user)
        ->test('pages::teams.edit', ['team' => $team])
        ->set('allianceId', $value)
        ->call('updateAlliance')
        ->assertHasErrors('allianceId');

    expect($team->refresh()->alliance_id)->toBeNull();
})->with([
    'too short' => 'abc123',
    'non hex characters' => 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz',
    'too long' => 'a1234567890123456789012345678901234',
]);

test('clearing the alliance id stores null', function () {
    $user = User::factory()->create();
    $team = ownedTeam($user, ['alliance_id' => allianceId()]);

    Livewire::actingAs($user)
        ->test('pages::teams.edit', ['team' => $team])
        ->set('allianceId', '')
        ->call('updateAlliance')
        ->assertHasNoErrors();

    expect($team->refresh()->alliance_id)->toBeNull();
});

test('a member cannot change the alliance id', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['alliance_id' => null]);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    Livewire::actingAs($user)
        ->test('pages::teams.edit', ['team' => $team])
        ->set('allianceId', allianceId())
        ->call('updateAlliance')
        ->assertForbidden();

    expect($team->refresh()->alliance_id)->toBeNull();
});

test('syncing queues an alliance roster import for the stored alliance', function () {
    Queue::fake();

    $user = User::factory()->create();
    $team = ownedTeam($user, ['alliance_id' => allianceId()]);

    Livewire::actingAs($user)
        ->test('pages::teams.edit', ['team' => $team])
        ->call('syncRoster')
        ->assertHasNoErrors();

    $import = Import::sole();

    expect($import->type)->toBe(ImportType::AllianceRoster)
        ->and($import->team_id)->toBe($team->id)
        ->and($import->user_id)->toBe($user->id)
        ->and($import->status)->toBe(ImportStatus::Processing)
        ->and($import->payload['alliance_id'])->toBe(allianceId());

    Queue::assertPushed(SyncAllianceMembers::class);
});

test('syncing is refused when the team has no alliance id', function () {
    Queue::fake();

    $user = User::factory()->create();
    $team = ownedTeam($user, ['alliance_id' => null]);

    Livewire::actingAs($user)
        ->test('pages::teams.edit', ['team' => $team])
        ->assertSet('canSyncRoster', false)
        ->call('syncRoster')
        ->assertHasNoErrors();

    expect(Import::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('syncing is refused when no api key is configured', function () {
    Queue::fake();
    config()->set('services.lastwar.key', null);

    $user = User::factory()->create();
    $team = ownedTeam($user, ['alliance_id' => allianceId()]);

    Livewire::actingAs($user)
        ->test('pages::teams.edit', ['team' => $team])
        ->assertSet('canSyncRoster', false)
        ->call('syncRoster');

    expect(Import::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('syncing is refused once the team already has a roster', function () {
    Queue::fake();

    $user = User::factory()->create();
    $team = ownedTeam($user, ['alliance_id' => allianceId()]);
    Member::factory()->for($team)->create();

    Livewire::actingAs($user)
        ->test('pages::teams.edit', ['team' => $team])
        ->assertSet('canSyncRoster', false)
        ->assertSeeHtml('data-test="alliance-sync-blocked"')
        ->call('syncRoster')
        ->assertHasNoErrors();

    expect(Import::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('a members roster on another team does not block syncing', function () {
    Queue::fake();

    $user = User::factory()->create();
    $team = ownedTeam($user, ['alliance_id' => allianceId()]);
    Member::factory()->for(Team::factory()->create())->create();

    Livewire::actingAs($user)
        ->test('pages::teams.edit', ['team' => $team])
        ->assertSet('canSyncRoster', true)
        ->call('syncRoster')
        ->assertHasNoErrors();

    expect(Import::count())->toBe(1);
});

test('a member cannot sync the roster', function () {
    Queue::fake();

    $user = User::factory()->create();
    $team = Team::factory()->create(['alliance_id' => allianceId()]);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    Livewire::actingAs($user)
        ->test('pages::teams.edit', ['team' => $team])
        ->call('syncRoster')
        ->assertForbidden();

    expect(Import::count())->toBe(0);
});

test('the configured fallback alliance id enables syncing', function () {
    Queue::fake();
    config()->set('services.lastwar.alliance_id', allianceId('b'));

    $user = User::factory()->create();
    $team = ownedTeam($user, ['alliance_id' => null]);

    Livewire::actingAs($user)
        ->test('pages::teams.edit', ['team' => $team])
        ->assertSet('canSyncRoster', true)
        ->call('syncRoster')
        ->assertHasNoErrors();

    expect(Import::sole()->payload['alliance_id'])->toBe(allianceId('b'));
});
