<?php

use App\Actions\Imports\FailImport;
use App\Actions\Imports\StartImport;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Events\ImportCompleted;
use App\Events\ImportFailed;
use App\Jobs\Imports\CompleteImport;
use App\Jobs\Imports\ImportRosterMembers;
use App\Listeners\SendImportCompletedNotification;
use App\Listeners\SendImportFailedNotification;
use App\Models\Import;
use App\Models\Member;
use App\Models\User;
use App\Notifications\Imports\ImportCompletedNotification;
use App\Notifications\Imports\ImportFailedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

test('starting an import records it and queues the workflow chain', function () {
    Bus::fake();
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $import = app(StartImport::class)->handle($team, $user, ImportType::Roster, [
        'members' => [['name' => 'Leader', 'position' => 'R5']],
    ]);

    expect($import->status)->toBe(ImportStatus::Processing)
        ->and($import->team_id)->toBe($team->id)
        ->and($import->user_id)->toBe($user->id)
        ->and($import->started_at)->not->toBeNull();

    $this->assertDatabaseHas('imports', [
        'id' => $import->id,
        'type' => 'roster',
        'status' => 'processing',
    ]);

    Bus::assertChained([ImportRosterMembers::class, CompleteImport::class]);
});

test('the roster step imports members and skips duplicates and cap breaches', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    Member::factory()->for($team)->create(['name' => 'Existing']);

    $import = Import::factory()->for($team)->create([
        'payload' => ['members' => [
            ['name' => 'Leader', 'position' => 'R5'],
            ['name' => 'Pretender', 'position' => 'R5'], // breaches the single-R5 cap
            ['name' => 'Existing', 'position' => 'R3'],   // already on the roster
            ['name' => 'Grunt', 'position' => 'R1'],
        ]],
    ]);

    (new ImportRosterMembers($import))->handle();

    $import->refresh();

    expect($import->current_step)->toBe('ImportRosterMembers')
        ->and($import->results['total'])->toBe(4)
        ->and($import->results['created'])->toBe(2)
        ->and($import->results['skipped'])->toHaveCount(2);

    $this->assertDatabaseHas('members', ['team_id' => $team->id, 'name' => 'Leader', 'position' => 'R5']);
    $this->assertDatabaseHas('members', ['team_id' => $team->id, 'name' => 'Grunt', 'position' => 'R1']);
    $this->assertDatabaseMissing('members', ['team_id' => $team->id, 'name' => 'Pretender']);
});

test('completing an import marks it completed and fires the event', function () {
    Event::fake([ImportCompleted::class]);
    $import = Import::factory()->processing()->create();

    (new CompleteImport($import))->handle();

    $import->refresh();

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->completed_at)->not->toBeNull();

    Event::assertDispatched(ImportCompleted::class, fn (ImportCompleted $e) => $e->import->is($import));
});

test('failing an import records the error and fires the event', function () {
    Event::fake([ImportFailed::class]);
    $import = Import::factory()->processing()->create();

    app(FailImport::class)->handle($import->id, new RuntimeException('boom'));

    $import->refresh();

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->error)->toBe('boom')
        ->and($import->failed_at)->not->toBeNull();

    Event::assertDispatched(ImportFailed::class, fn (ImportFailed $e) => $e->import->is($import));
});

test('the completion listener emails the creator from a background job', function () {
    Notification::fake();
    $creator = User::factory()->create();
    $import = Import::factory()->for($creator, 'creator')->completed()->create();

    expect(new SendImportCompletedNotification)->toBeInstanceOf(ShouldQueue::class);

    (new SendImportCompletedNotification)->handle(new ImportCompleted($import));

    Notification::assertSentTo($creator, ImportCompletedNotification::class);
});

test('the completion mail reports updated records for an overwriting import', function () {
    $creator = User::factory()->create();
    $import = Import::factory()->for($creator, 'creator')->vsScores()->completed()->create([
        'results' => ['total' => 3, 'created' => 1, 'updated' => 2, 'skipped' => []],
    ]);

    $lines = (new ImportCompletedNotification($import))->toMail($creator)->introLines;

    expect($lines)->toContain('1 of 3 records were imported.')
        ->and($lines)->toContain('2 existing records were updated.');
});

test('the completion mail omits the updated line for an import that never overwrites', function () {
    $creator = User::factory()->create();
    $import = Import::factory()->for($creator, 'creator')->completed()->create();

    $lines = (new ImportCompletedNotification($import))->toMail($creator)->introLines;

    expect($lines)->toContain('1 of 1 records were imported.')
        ->and(collect($lines)->filter(fn (string $line): bool => str_contains($line, 'updated')))->toBeEmpty();
});

test('the failure listener emails the creator from a background job', function () {
    Notification::fake();
    $creator = User::factory()->create();
    $import = Import::factory()->for($creator, 'creator')->failed()->create();

    expect(new SendImportFailedNotification)->toBeInstanceOf(ShouldQueue::class);

    (new SendImportFailedNotification)->handle(new ImportFailed($import));

    Notification::assertSentTo($creator, ImportFailedNotification::class);
});
