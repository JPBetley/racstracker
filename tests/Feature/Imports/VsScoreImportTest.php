<?php

use App\Actions\Imports\ConfirmVsScoreImport;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Imports\Ocr\Contracts\VsScoreScreenshotReader;
use App\Jobs\Imports\CompleteImport;
use App\Jobs\Imports\ImportVsScores;
use App\Jobs\Imports\ParseVsScoreScreenshots;
use App\Models\Import;
use App\Models\Member;
use App\Models\Score;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Livewire\Features\SupportFileUploads\S3DoesntSupportMultipleFileUploads;
use Livewire\Livewire;

// 2025-01-06 is a Monday, so it is the start of the VS week.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2025-01-06 10:00:00'));
});

/**
 * Bind a stub OCR reader so tests never make a real vision API call.
 *
 * @param  array<int, array{rank: int, name: string, points: int}>  $rows
 */
function fakeVsScoreReader(array $rows): void
{
    app()->bind(VsScoreScreenshotReader::class, fn (): VsScoreScreenshotReader => new class($rows) implements VsScoreScreenshotReader
    {
        public function __construct(private array $rows) {}

        public function read(array $imagePaths, ?string $disk = null): array
        {
            return $this->rows;
        }
    });
}

/**
 * Sign a user in, scope routes to their team, and return both.
 *
 * @return array{0: User, 1: Team}
 */
function vsImportActor(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    URL::defaults(['current_team' => $team->slug]);

    return [$user, $team];
}

test('the parse job stores a draft and parks the import for review', function () {
    fakeVsScoreReader([['rank' => 1, 'name' => 'Femme de Fatale', 'points' => 59882250]]);

    $import = Import::factory()->vsScores()->processing()->create([
        'payload' => ['week_start' => '2025-01-06', 'screenshots' => ['imports/a.png']],
    ]);

    (new ParseVsScoreScreenshots($import))->handle(app(VsScoreScreenshotReader::class));

    $import->refresh();

    expect($import->status)->toBe(ImportStatus::AwaitingReview)
        ->and($import->payload['rows'])->toBe([['rank' => 1, 'name' => 'Femme de Fatale', 'points' => 59882250]]);
});

test('the parse job records a failure when OCR throws', function () {
    app()->bind(VsScoreScreenshotReader::class, fn (): VsScoreScreenshotReader => new class implements VsScoreScreenshotReader
    {
        public function read(array $imagePaths, ?string $disk = null): array
        {
            throw new RuntimeException('ocr exploded');
        }
    });

    $import = Import::factory()->vsScores()->processing()->create([
        'payload' => ['week_start' => '2025-01-06', 'screenshots' => ['imports/a.png']],
    ]);

    (new ParseVsScoreScreenshots($import))->handle(app(VsScoreScreenshotReader::class));

    expect($import->refresh()->status)->toBe(ImportStatus::Failed)
        ->and($import->error)->toBe('ocr exploded');
});

test('the parse job records a failure when the job dies before handle runs', function () {
    // Dependency resolution happens outside handle()'s try/catch, so a stale queue
    // worker missing the reader binding once left imports stuck at "processing".
    $import = Import::factory()->vsScores()->processing()->create();

    (new ParseVsScoreScreenshots($import))->failed(new RuntimeException('container exploded'));

    expect($import->refresh()->status)->toBe(ImportStatus::Failed)
        ->and($import->error)->toBe('container exploded');
});

test('screenshots are stored on the configured disk, not a hardcoded local one', function () {
    // In production the OCR runs on a queue worker with its own empty filesystem, so
    // the upload has to land on whatever shared disk is configured rather than on the
    // web machine's local storage. Faking a disk that is not "local" is the only way
    // this stays true — the two are indistinguishable when the default is local.
    config()->set('filesystems.default', 'shared');
    Storage::fake('shared');

    [$user] = vsImportActor();
    fakeVsScoreReader([]);

    Livewire::actingAs($user)
        ->test('pages::scores.import')
        ->set('screenshots', [UploadedFile::fake()->image('vs-1.png')])
        ->call('startParse')
        ->assertHasNoErrors();

    $stored = Storage::disk('shared')->files('imports');

    expect($stored)->toHaveCount(1);
});

test('the parse job hands the reader disk paths rather than filesystem paths', function () {
    // Storage::disk()->path() would resolve to a directory that does not exist on the
    // worker; the reader has to receive the stored path untouched so it can read it
    // back off the disk itself.
    $seen = new ArrayObject;

    app()->bind(VsScoreScreenshotReader::class, fn (): VsScoreScreenshotReader => new class($seen) implements VsScoreScreenshotReader
    {
        public function __construct(private ArrayObject $seen) {}

        public function read(array $imagePaths, ?string $disk = null): array
        {
            $this->seen['paths'] = $imagePaths;

            return [];
        }
    });

    $import = Import::factory()->vsScores()->processing()->create([
        'payload' => ['week_start' => '2025-01-06', 'screenshots' => ['imports/a.png', 'imports/b.png']],
    ]);

    (new ParseVsScoreScreenshots($import))->handle(app(VsScoreScreenshotReader::class));

    expect($seen['paths'])->toBe(['imports/a.png', 'imports/b.png']);
});

test('the parse job leaves an overloaded provider to the queue rather than failing the import', function () {
    // An overloaded vision provider has said nothing about the screenshots, so the
    // import is not failed — it is retried. Marking it failed here would strand the
    // user on "we could not read those screenshots" for a transient rate limit.
    app()->bind(VsScoreScreenshotReader::class, fn (): VsScoreScreenshotReader => new class implements VsScoreScreenshotReader
    {
        public function read(array $imagePaths, ?string $disk = null): array
        {
            throw ProviderOverloadedException::forProvider('gemini');
        }
    });

    $import = Import::factory()->vsScores()->processing()->create([
        'payload' => ['week_start' => '2025-01-06', 'screenshots' => ['imports/a.png']],
    ]);

    expect(fn () => (new ParseVsScoreScreenshots($import))->handle(app(VsScoreScreenshotReader::class)))
        ->toThrow(ProviderOverloadedException::class);

    expect($import->refresh()->status)->toBe(ImportStatus::Processing);
});

test('the review page explains an empty draft instead of showing a bare row', function () {
    Storage::fake('local');
    [$user] = vsImportActor();

    fakeVsScoreReader([]);

    Livewire::actingAs($user)
        ->test('pages::scores.import')
        ->set('screenshots', [UploadedFile::fake()->image('vs-1.png')])
        ->call('startParse')
        ->assertSet('rows', [])
        ->assertSee('No scores were read from those screenshots');
});

test('screenshots can be uploaded, matched to members, and imported as weekly scores', function () {
    Storage::fake('local');
    [$user, $team] = vsImportActor();

    $first = Member::factory()->for($team)->create(['name' => 'Femme de Fatale']);
    $second = Member::factory()->for($team)->create(['name' => 'Papagoose89']);

    fakeVsScoreReader([
        ['rank' => 1, 'name' => 'Femme de Fatale', 'points' => 59882250],
        ['rank' => 2, 'name' => 'Papagoose89', 'points' => 56137674],
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::scores.import')
        ->set('screenshots', [UploadedFile::fake()->image('vs-1.png')])
        ->call('startParse')
        ->assertHasNoErrors();

    // The queue runs synchronously in tests, so the draft is already loaded.
    $component->assertSet('rows.0.name', 'Femme de Fatale')
        ->assertSet('rows.0.member_id', $first->id)
        ->assertSet('rows.1.member_id', $second->id)
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertRedirect(route('scores.index', ['weekOffset' => 0]));

    $this->assertDatabaseHas('scores', [
        'member_id' => $first->id,
        'week_start' => '2025-01-06 00:00:00',
        'points' => 59882250,
    ]);
    $this->assertDatabaseHas('scores', [
        'member_id' => $second->id,
        'week_start' => '2025-01-06 00:00:00',
        'points' => 56137674,
    ]);
});

test('a fuzzy OCR name is matched to the right member via the name matcher', function () {
    Storage::fake('local');
    [$user, $team] = vsImportActor();

    $member = Member::factory()->for($team)->create(['name' => 'Whiskey Brain']);

    fakeVsScoreReader([['rank' => 3, 'name' => 'Wh1skey Braln', 'points' => 43024498]]);

    Livewire::actingAs($user)
        ->test('pages::scores.import')
        ->set('screenshots', [UploadedFile::fake()->image('vs-1.png')])
        ->call('startParse')
        ->assertSet('rows.0.member_id', $member->id)
        ->assertSet('rows.0.suggestion', 'Whiskey Brain')
        ->call('confirm')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('scores', ['member_id' => $member->id, 'points' => 43024498]);
});

test('an unmatched commander is skipped and reported rather than creating a member', function () {
    Storage::fake('local');
    [$user, $team] = vsImportActor();

    Member::factory()->for($team)->create(['name' => 'Femme de Fatale']);

    fakeVsScoreReader([['rank' => 9, 'name' => 'Rival From Another Alliance', 'points' => 1234]]);

    Livewire::actingAs($user)
        ->test('pages::scores.import')
        ->set('screenshots', [UploadedFile::fake()->image('vs-1.png')])
        ->call('startParse')
        ->assertSet('rows.0.member_id', null)
        ->call('confirm')
        ->assertHasNoErrors();

    $import = $team->imports()->where('type', ImportType::VsScores)->sole();

    expect($import->results['skipped'])->toBe([
        ['name' => 'Rival From Another Alliance', 'reason' => 'No matching roster member.'],
    ]);

    $this->assertDatabaseMissing('members', ['name' => 'Rival From Another Alliance']);
    expect(Score::count())->toBe(0);
});

test('importing overwrites an existing score for the same member and week', function () {
    Storage::fake('local');
    [$user, $team] = vsImportActor();

    $member = Member::factory()->for($team)->create(['name' => 'Femme de Fatale']);
    Score::factory()->for($member)->forWeek(CarbonImmutable::parse('2025-01-06'))->create(['points' => 100]);

    fakeVsScoreReader([['rank' => 1, 'name' => 'Femme de Fatale', 'points' => 5000]]);

    Livewire::actingAs($user)
        ->test('pages::scores.import')
        ->set('screenshots', [UploadedFile::fake()->image('vs-1.png')])
        ->call('startParse')
        ->assertSet('rows.0.existing', 100)
        ->call('confirm')
        ->assertHasNoErrors();

    $import = $team->imports()->where('type', ImportType::VsScores)->sole();

    expect(Score::count())->toBe(1)
        ->and($member->scores()->sole()->points)->toBe(5000)
        ->and($import->results['updated'])->toBe(1)
        ->and($import->results['created'])->toBe(0);
});

test('the import targets the week carried through from the scores page', function () {
    Storage::fake('local');
    [$user, $team] = vsImportActor();

    $member = Member::factory()->for($team)->create(['name' => 'Femme de Fatale']);

    fakeVsScoreReader([['rank' => 1, 'name' => 'Femme de Fatale', 'points' => 777]]);

    Livewire::actingAs($user)
        ->test('pages::scores.import', ['weekOffset' => -1])
        ->set('screenshots', [UploadedFile::fake()->image('vs-1.png')])
        ->call('startParse')
        ->call('confirm')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('scores', [
        'member_id' => $member->id,
        'week_start' => '2024-12-30 00:00:00',
        'points' => 777,
    ]);
});

test('the week can still be changed during review', function () {
    Storage::fake('local');
    [$user, $team] = vsImportActor();

    $member = Member::factory()->for($team)->create(['name' => 'Femme de Fatale']);

    fakeVsScoreReader([['rank' => 1, 'name' => 'Femme de Fatale', 'points' => 888]]);

    Livewire::actingAs($user)
        ->test('pages::scores.import')
        ->set('screenshots', [UploadedFile::fake()->image('vs-1.png')])
        ->call('startParse')
        ->call('previousWeek')
        ->call('confirm')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('scores', [
        'member_id' => $member->id,
        'week_start' => '2024-12-30 00:00:00',
        'points' => 888,
    ]);
});

test('the next week control cannot move past the current week', function () {
    [$user] = vsImportActor();

    Livewire::actingAs($user)
        ->test('pages::scores.import')
        ->call('nextWeek')
        ->assertSet('weekOffset', 0);
});

test('a duplicate row for the same member is skipped', function () {
    Storage::fake('local');
    [$user, $team] = vsImportActor();

    $member = Member::factory()->for($team)->create(['name' => 'Femme de Fatale']);

    fakeVsScoreReader([
        ['rank' => 1, 'name' => 'Femme de Fatale', 'points' => 5000],
        ['rank' => 2, 'name' => 'Femme de Fatale', 'points' => 9999],
    ]);

    Livewire::actingAs($user)
        ->test('pages::scores.import')
        ->set('screenshots', [UploadedFile::fake()->image('vs-1.png')])
        ->call('startParse')
        ->call('confirm')
        ->assertHasNoErrors();

    $import = $team->imports()->where('type', ImportType::VsScores)->sole();

    expect(Score::count())->toBe(1)
        ->and($member->scores()->sole()->points)->toBe(5000)
        ->and($import->results['skipped'])->toBe([
            ['name' => 'Femme de Fatale', 'reason' => 'Duplicate row for this member.'],
        ]);
});

test('scores cannot be imported for a member of another team', function () {
    Storage::fake('local');
    [$user, $team] = vsImportActor();

    $foreign = Member::factory()->for(Team::factory())->create(['name' => 'Outsider']);

    fakeVsScoreReader([['rank' => 1, 'name' => 'Outsider', 'points' => 5000]]);

    Livewire::actingAs($user)
        ->test('pages::scores.import')
        ->set('screenshots', [UploadedFile::fake()->image('vs-1.png')])
        ->call('startParse')
        ->set('rows.0.member_id', $foreign->id)
        ->call('confirm')
        ->assertHasNoErrors();

    $import = $team->imports()->where('type', ImportType::VsScores)->sole();

    expect($import->results['skipped'])->toBe([
        ['name' => 'Outsider', 'reason' => 'Not on this roster.'],
    ]);

    $this->assertDatabaseMissing('scores', ['member_id' => $foreign->id]);
});

test('the vs scores type queues its workflow chain', function () {
    Bus::fake();
    [$user, $team] = vsImportActor();

    $import = Import::factory()->vsScores()->create(['team_id' => $team->id, 'user_id' => $user->id]);

    app(ConfirmVsScoreImport::class)
        ->handle($import, CarbonImmutable::parse('2025-01-06'), []);

    Bus::assertChained([ImportVsScores::class, CompleteImport::class]);
});

test('the import page renders for an authenticated member', function () {
    [$user] = vsImportActor();

    $this->actingAs($user)
        ->get(route('scores.import'))
        ->assertOk()
        ->assertSee('Import VS scores');
});

test('guests are redirected from the import page', function () {
    vsImportActor();

    $this->get(route('scores.import'))->assertRedirect(route('login'));
});

test('users cannot import for a team they do not belong to', function () {
    [$user] = vsImportActor();
    $otherTeam = Team::factory()->create();

    $this->actingAs($user)
        ->get(route('scores.import', ['current_team' => $otherTeam->slug]))
        ->assertForbidden();
});

test('the scores page links to the import page for the week being viewed', function () {
    [$user] = vsImportActor();

    Livewire::actingAs($user)
        ->test('pages::scores.index')
        ->call('previousWeek')
        ->assertSee(route('scores.import', ['weekOffset' => -1]), escape: false);
});

/**
 * Point Livewire's temporary uploads at a bucket, the way production does.
 */
function useS3TemporaryUploads(): void
{
    config()->set('filesystems.disks.s3', [
        'driver' => 's3',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'region' => 'us-east-1',
        'bucket' => 'test-bucket',
    ]);

    config()->set('livewire.temporary_file_upload.disk', 's3');
}

test('the S3 temporary upload disk refuses a multiple upload', function () {
    // Production keeps temporary uploads in the bucket, and that driver signs one file
    // per request: handing it a batch throws before a single byte moves. This is what
    // a plain `wire:model` on a `multiple` input does, so the page must not use one.
    useS3TemporaryUploads();

    [$user] = vsImportActor();

    Livewire::actingAs($user)
        ->test('pages::scores.import')
        ->upload('screenshots', [UploadedFile::fake()->image('vs-1.png')], isMultiple: true);
})->throws(S3DoesntSupportMultipleFileUploads::class);

test('the upload field is not bound with wire:model', function () {
    // The binding is what makes Livewire send the whole selection as one multiple
    // upload. The page uploads a file at a time from Alpine instead.
    [$user] = vsImportActor();

    Livewire::actingAs($user)
        ->test('pages::scores.import')
        ->assertSee('data-test="vs-screenshot-input"', escape: false)
        ->assertDontSee('wire:model="screenshots"', escape: false);
});

test('screenshots uploaded one at a time are appended to the batch', function () {
    [$user] = vsImportActor();

    $component = Livewire::actingAs($user)
        ->test('pages::scores.import')
        ->upload('screenshots', [UploadedFile::fake()->image('vs-1.png')])
        ->upload('screenshots', [UploadedFile::fake()->image('vs-2.png')]);

    expect($component->get('screenshots'))->toHaveCount(2);

    $component->call('startParse')->assertHasNoErrors();

    expect(Import::sole()->payload['screenshots'])->toHaveCount(2);
});

test('a screenshot can be dropped from the batch before parsing', function () {
    [$user] = vsImportActor();

    $component = Livewire::actingAs($user)
        ->test('pages::scores.import')
        ->upload('screenshots', [UploadedFile::fake()->image('vs-1.png')])
        ->upload('screenshots', [UploadedFile::fake()->image('vs-2.png')])
        ->call('removeScreenshot', 0);

    $remaining = $component->get('screenshots');

    expect($remaining)->toHaveCount(1)
        ->and(array_keys($remaining))->toBe([0])
        ->and($remaining[0]->getClientOriginalName())->toBe('vs-2.png');
});
