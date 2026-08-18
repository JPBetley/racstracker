<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2025-01-06 10:00:00'));
    Storage::fake();
});

/**
 * Put a screenshot on the default disk, backdated by the given number of hours.
 */
function agedScreenshot(string $path, int $hoursOld): void
{
    Storage::disk()->putFileAs(
        dirname($path),
        UploadedFile::fake()->image(basename($path)),
        basename($path),
    );

    touch(Storage::disk()->path($path), CarbonImmutable::now()->subHours($hoursOld)->getTimestamp());
}

test('it deletes screenshots past the retention window and keeps recent ones', function () {
    agedScreenshot('imports/old.png', 48);
    agedScreenshot('imports/fresh.png', 1);

    $this->artisan('imports:prune-screenshots')->assertSuccessful();

    Storage::disk()->assertMissing('imports/old.png');
    Storage::disk()->assertExists('imports/fresh.png');
});

test('it sweeps abandoned Livewire uploads too', function () {
    // Livewire only clears these when the storage backend expires them on a lifecycle
    // rule, which object storage here does not do — so an upload the user never
    // submitted would otherwise sit on the disk forever.
    agedScreenshot('livewire-tmp/abandoned.png', 48);

    $this->artisan('imports:prune-screenshots')->assertSuccessful();

    Storage::disk()->assertMissing('livewire-tmp/abandoned.png');
});

test('the retention window is configurable', function () {
    agedScreenshot('imports/recent.png', 3);

    $this->artisan('imports:prune-screenshots', ['--hours' => 1])->assertSuccessful();

    Storage::disk()->assertMissing('imports/recent.png');
});

test('it succeeds when there is nothing to prune', function () {
    $this->artisan('imports:prune-screenshots')
        ->expectsOutputToContain('Pruned 0 screenshot(s)')
        ->assertSuccessful();
});
