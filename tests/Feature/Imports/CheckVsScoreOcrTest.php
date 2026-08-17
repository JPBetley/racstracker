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
        public function read(array $imagePaths): array
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
        public function read(array $imagePaths): array
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

test('the --provider and --model options reach the reader for the run', function () {
    // The whole point of the options is A/B-ing two backends without editing .env, so
    // what matters is that the reader sees the override at the moment it reads.
    $seen = new ArrayObject;

    app()->bind(VsScoreScreenshotReader::class, fn (): VsScoreScreenshotReader => new class($seen) implements VsScoreScreenshotReader
    {
        public function __construct(private ArrayObject $seen) {}

        public function read(array $imagePaths): array
        {
            $this->seen['provider'] = config('vs.ocr.provider');
            $this->seen['model'] = config('vs.ocr.model');

            return vsGroundTruth();
        }
    });

    $this->artisan('vs:ocr-check', [
        'dir' => 'tests/stubs/vs',
        '--provider' => 'gemini',
        '--model' => 'gemini-model-under-test',
    ])
        ->expectsOutputToContain('via gemini/gemini-model-under-test')
        ->assertSuccessful();

    expect($seen->getArrayCopy())->toBe(['provider' => 'gemini', 'model' => 'gemini-model-under-test']);
});

test('it leaves the configured backend alone when no override is passed', function () {
    config()->set('vs.ocr.provider', 'anthropic');
    config()->set('vs.ocr.model', 'claude-model-from-config');

    app()->bind(VsScoreScreenshotReader::class, fn (): VsScoreScreenshotReader => new class implements VsScoreScreenshotReader
    {
        public function read(array $imagePaths): array
        {
            return vsGroundTruth();
        }
    });

    $this->artisan('vs:ocr-check', ['dir' => 'tests/stubs/vs'])
        ->expectsOutputToContain('via anthropic/claude-model-from-config')
        ->assertSuccessful();
});

test('it fails when the fixture directory has no ground truth', function () {
    $this->artisan('vs:ocr-check', ['dir' => 'tests/stubs/team'])
        ->expectsOutputToContain('Expected *.png screenshots and scores.json')
        ->assertFailed();
});
