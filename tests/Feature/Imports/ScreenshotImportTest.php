<?php

use App\Enums\ImportStatus;
use App\Imports\Ocr\Contracts\RosterScreenshotReader;
use App\Jobs\Imports\ParseRosterScreenshots;
use App\Models\Import;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/**
 * Bind a stub OCR reader so tests never make a real vision API call.
 *
 * @param  array<int, array{name: string, position: string}>  $members
 */
function fakeRosterReader(array $members): void
{
    app()->bind(RosterScreenshotReader::class, fn (): RosterScreenshotReader => new class($members) implements RosterScreenshotReader
    {
        public function __construct(private array $members) {}

        public function read(array $imagePaths): array
        {
            return $this->members;
        }
    });
}

test('the parse job stores a draft and parks the import for review', function () {
    fakeRosterReader([['name' => 'Leader', 'position' => 'R5']]);

    $import = Import::factory()->processing()->create([
        'payload' => ['screenshots' => ['imports/a.png']],
    ]);

    (new ParseRosterScreenshots($import))->handle(app(RosterScreenshotReader::class));

    $import->refresh();

    expect($import->status)->toBe(ImportStatus::AwaitingReview)
        ->and($import->payload['members'])->toBe([['name' => 'Leader', 'position' => 'R5']]);
});

test('the parse job records a failure when OCR throws', function () {
    app()->bind(RosterScreenshotReader::class, fn (): RosterScreenshotReader => new class implements RosterScreenshotReader
    {
        public function read(array $imagePaths): array
        {
            throw new RuntimeException('ocr exploded');
        }
    });

    $import = Import::factory()->processing()->create([
        'payload' => ['screenshots' => ['imports/a.png']],
    ]);

    (new ParseRosterScreenshots($import))->handle(app(RosterScreenshotReader::class));

    expect($import->refresh()->status)->toBe(ImportStatus::Failed)
        ->and($import->error)->toBe('ocr exploded');
});

test('screenshots can be uploaded, reviewed, and imported into the roster', function () {
    Storage::fake('local');
    fakeRosterReader([
        ['name' => 'I am Mr Yeti', 'position' => 'R5'],
        ['name' => 'Whiskey Brain', 'position' => 'R4'],
    ]);

    $user = User::factory()->create();
    $team = $user->currentTeam;
    URL::defaults(['current_team' => $team->slug]);

    $component = Livewire::actingAs($user)
        ->test('pages::members.import')
        ->set('screenshots', [UploadedFile::fake()->image('team-1.png')])
        ->call('startParse')
        ->assertHasNoErrors();

    // The queue runs synchronously in tests, so the draft is already loaded.
    $component->assertSet('rows.0.name', 'I am Mr Yeti')
        ->assertSet('rows.1.name', 'Whiskey Brain')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertRedirect(route('members.index'));

    $this->assertDatabaseHas('members', ['team_id' => $team->id, 'name' => 'I am Mr Yeti', 'position' => 'R5']);
    $this->assertDatabaseHas('members', ['team_id' => $team->id, 'name' => 'Whiskey Brain', 'position' => 'R4']);
});

test('a reviewer can correct a parsed name before importing', function () {
    Storage::fake('local');
    fakeRosterReader([['name' => 'Wh1skey Braln', 'position' => 'R4']]);

    $user = User::factory()->create();
    $team = $user->currentTeam;
    URL::defaults(['current_team' => $team->slug]);

    Livewire::actingAs($user)
        ->test('pages::members.import')
        ->set('screenshots', [UploadedFile::fake()->image('team-1.png')])
        ->call('startParse')
        ->set('rows.0.name', 'Whiskey Brain')
        ->call('confirm')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('members', ['team_id' => $team->id, 'name' => 'Whiskey Brain', 'position' => 'R4']);
    $this->assertDatabaseMissing('members', ['name' => 'Wh1skey Braln']);
});

test('the import page renders for an authenticated member', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('members.import'))
        ->assertOk()
        ->assertSee('Import roster from screenshots');
});
