<?php

use App\Imports\Ocr\Contracts\VsScoreScreenshotReader;

/**
 * The committed ground truth for the tests/stubs/vs captures.
 *
 * @return array<int, array{rank: int, name: string, points: int}>
 */
function vsGroundTruth(): array
{
    $scores = json_decode((string) file_get_contents(base_path('tests/stubs/vs/scores.json')), true)['scores'];

    return array_map(
        fn (array $row, int $index): array => ['rank' => $index + 1, 'name' => $row['name'], 'points' => $row['points']],
        $scores,
        array_keys($scores),
    );
}

test('the ground truth fixture covers every commander exactly once', function () {
    $rows = vsGroundTruth();
    $names = array_map(fn (array $row): string => mb_strtolower($row['name']), $rows);

    expect($rows)->toHaveCount(83)
        ->and(array_unique($names))->toHaveCount(83);
});

test('it reports full accuracy when the reader matches the ground truth', function () {
    app()->bind(VsScoreScreenshotReader::class, fn (): VsScoreScreenshotReader => new class implements VsScoreScreenshotReader
    {
        public function read(array $imagePaths, ?string $disk = null): array
        {
            return vsGroundTruth();
        }
    });

    $this->artisan('vs:ocr-check', ['dir' => 'tests/stubs/vs'])
        ->expectsOutputToContain('Accuracy: 100%')
        ->assertSuccessful();
});

test('it reports the exact discrepancy when the reader misreads a total', function () {
    app()->bind(VsScoreScreenshotReader::class, fn (): VsScoreScreenshotReader => new class implements VsScoreScreenshotReader
    {
        public function read(array $imagePaths, ?string $disk = null): array
        {
            $rows = vsGroundTruth();

            // Drop a digit from the leader's total, the failure this harness exists to catch.
            $rows[0]['points'] = 5988225;

            return $rows;
        }
    });

    $this->artisan('vs:ocr-check', ['dir' => 'tests/stubs/vs'])
        ->expectsOutputToContain('femme de fatale: expected 59,882,250 → parsed 5,988,225')
        ->assertSuccessful();
});

test('it reads through the reader the app resolves', function () {
    // The run has to stay on the exact path production takes, or the accuracy it reports
    // is not the accuracy an import would get.
    $reader = new class implements VsScoreScreenshotReader
    {
        public bool $read = false;

        public function read(array $imagePaths, ?string $disk = null): array
        {
            $this->read = true;

            return vsGroundTruth();
        }
    };

    app()->bind(VsScoreScreenshotReader::class, fn (): VsScoreScreenshotReader => $reader);

    $this->artisan('vs:ocr-check', ['dir' => 'tests/stubs/vs'])->assertSuccessful();

    expect($reader->read)->toBeTrue();
});

test('it fails when the fixture directory has no ground truth', function () {
    $this->artisan('vs:ocr-check', ['dir' => 'tests/stubs/team'])
        ->expectsOutputToContain('Expected *.png screenshots and scores.json')
        ->assertFailed();
});
