<?php

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Models\Import;
use App\Models\Member;
use App\Models\MemberAlias;
use App\Models\User;
use Livewire\Livewire;

/**
 * Put an import in front of the reviewer with a single parsed row.
 */
function awaitingReview(User $user, string $parsedName): Import
{
    return Import::factory()->for($user->currentTeam)->for($user, 'creator')->create([
        'type' => ImportType::VsScores,
        'status' => ImportStatus::AwaitingReview,
        'payload' => [
            'week_start' => '2026-08-10',
            'screenshots' => ['imports/vs-1.png'],
            'rows' => [['rank' => 1, 'name' => $parsedName, 'points' => 1_000_000]],
        ],
    ]);
}

test('the review member combobox can be searched by a member\'s former names', function () {
    $user = User::factory()->create();
    $member = Member::factory()->for($user->currentTeam)->create(['name' => 'Ravager']);
    MemberAlias::factory()->for($member)->create(['name' => 'OldRavager']);

    $import = awaitingReview($user, 'Ravager');

    Livewire::actingAs($user)
        ->test('pages::scores.import')
        ->set('importId', $import->id)
        ->call('pollDraft')
        ->assertSee('autocomplete="strict"', escape: false)
        ->assertSee('<span hidden>OldRavager</span>', escape: false);
});

test('a review row left unmatched is still skipped', function () {
    $user = User::factory()->create();
    Member::factory()->for($user->currentTeam)->create(['name' => 'Ravager']);

    $import = awaitingReview($user, 'NobodyKnowsThisName');

    $component = Livewire::actingAs($user)
        ->test('pages::scores.import')
        ->set('importId', $import->id)
        ->call('pollDraft');

    expect($component->get('rows')[0]['member_id'])->toBeNull();

    $component->assertSee('Will be skipped');
});
