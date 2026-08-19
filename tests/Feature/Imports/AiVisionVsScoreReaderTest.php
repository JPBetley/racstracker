<?php

use App\Ai\Agents\VsScoreScreenshotExtractor;
use App\Imports\Ocr\AiVisionVsScoreScreenshotReader;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\RateLimitedException;

/**
 * Register the committed captures as a disk, mirroring how the reader is fed in
 * production: disk-relative paths, never a local filesystem path.
 */
beforeEach(function () {
    config()->set('filesystems.disks.vs-fixtures', [
        'driver' => 'local',
        'root' => base_path('tests/stubs/vs'),
    ]);
});

test('it parses points, cleans names, and de-duplicates the rows returned by the vision agent', function () {
    VsScoreScreenshotExtractor::fake(fn () => [
        'rows' => [
            ['rank' => 1, 'name' => 'Femme de Fatale', 'points' => '59,882,250'],
            ['rank' => 46, 'name' => 'Tatsumori', 'points' => '7,994,663'],
            ['rank' => 46, 'name' => 'tatsumori', 'points' => '7,994,663'], // pinned self-row, repeated on every capture
            ['rank' => 5, 'name' => 'J E F F I', 'points' => null],          // clipped points dropped
            ['rank' => 6, 'name' => '', 'points' => '1,000'],                // empty name dropped
            ['rank' => 7, 'name' => '木木666', 'points' => '500'],             // non-Latin stripped to "666"
            ['rank' => 8, 'name' => 'Zero Hero', 'points' => '0'],           // a zero score is legitimate
        ],
    ]);

    $rows = (new AiVisionVsScoreScreenshotReader)->read(['vs-1.png', 'vs-2.png'], 'vs-fixtures');

    expect($rows)->toBe([
        ['rank' => 1, 'name' => 'Femme de Fatale', 'points' => 59882250],
        ['rank' => 46, 'name' => 'Tatsumori', 'points' => 7994663],
        ['rank' => 7, 'name' => '666', 'points' => 500],
        ['rank' => 8, 'name' => 'Zero Hero', 'points' => 0],
    ]);

    VsScoreScreenshotExtractor::assertPrompted(fn ($prompt) => $prompt->contains('leaderboard'));
});

test('it keeps the base letter of a decorated Latin name but drops other scripts', function () {
    // Names players actually use in this alliance. A decorated Latin letter has to
    // survive as its base letter, or RosterNameMatcher can no longer reach the member.
    VsScoreScreenshotExtractor::fake(fn () => [
        'rows' => [
            ['rank' => 69, 'name' => 'Bęęfaronį', 'points' => '4,549,805'],
            ['rank' => 61, 'name' => 'Яepoman46', 'points' => '5,904,263'],
            ['rank' => 62, 'name' => 'Good Luck굿럭', 'points' => '5,522,348'],
            ['rank' => 51, 'name' => '木木666', 'points' => '7,457,163'],
        ],
    ]);

    $rows = (new AiVisionVsScoreScreenshotReader)->read(['vs-1.png'], 'vs-fixtures');

    expect(array_column($rows, 'name'))->toBe(['666', 'epoman46', 'Good Luck', 'Beefaroni']);
});

test('it sends every screenshot to Gemini Flash in a single request', function () {
    VsScoreScreenshotExtractor::fake(fn () => ['rows' => []]);

    (new AiVisionVsScoreScreenshotReader)->read(['vs-1.png', 'vs-2.png'], 'vs-fixtures');

    VsScoreScreenshotExtractor::assertPrompted(
        fn ($prompt) => $prompt->provider->driver() === Lab::Gemini->value
            && $prompt->model === 'gemini-flash-latest'
            && $prompt->attachments->count() === 2
    );
});

test('it fails over to each provider in turn when one is rate limited', function () {
    // An import is a person waiting on a screen with their screenshots already uploaded,
    // so a rate-limited leader has to cost cents rather than the whole run. Each link
    // carries its own model, which is the part a plain list of providers would lose.
    $attempted = [];

    VsScoreScreenshotExtractor::fake(function ($prompt, $attachments, $provider, $model) use (&$attempted) {
        $attempted[] = "{$provider->driver()}/{$model}";

        // Only the last link in the chain answers, so a chain that stops short fails here.
        return $provider->driver() === 'anthropic'
            ? ['rows' => [['rank' => 1, 'name' => 'Femme de Fatale', 'points' => '59,882,250']]]
            : throw RateLimitedException::forProvider($provider->driver());
    });

    $rows = (new AiVisionVsScoreScreenshotReader)->read(['vs-1.png'], 'vs-fixtures');

    expect($attempted)->toBe([
        'gemini/gemini-flash-latest',
        'openai/gpt-5.4',
        'anthropic/claude-sonnet-5',
    ])->and($rows)->toBe([['rank' => 1, 'name' => 'Femme de Fatale', 'points' => 59882250]]);
});

test('it does not fail over on an error the next provider would fail on too', function () {
    // Failover exists for a busy provider, not a broken request. Retrying a bad prompt
    // down the whole chain would spend three calls to arrive at the same error.
    $attempts = 0;

    VsScoreScreenshotExtractor::fake(function () use (&$attempts) {
        $attempts++;

        throw new RuntimeException('Invalid request.');
    });

    expect(fn () => (new AiVisionVsScoreScreenshotReader)->read(['vs-1.png'], 'vs-fixtures'))
        ->toThrow(RuntimeException::class, 'Invalid request.')
        ->and($attempts)->toBe(1);
});

test('the prompt does not instruct the model to withhold rows', function () {
    // The agent once told the model to return nothing for a Daily Rank capture, which
    // surfaced as a silently empty review table. The app stores one score per week and
    // does not care which tab was captured, so no rule may suppress rows.
    $instructions = (new VsScoreScreenshotExtractor)->instructions();

    expect($instructions)->toContain('Never withhold rows')
        ->and($instructions)->not->toContain('return no rows');
});

test('it returns nothing when given no screenshots and never calls the model', function () {
    VsScoreScreenshotExtractor::fake()->preventStrayPrompts();

    $rows = (new AiVisionVsScoreScreenshotReader)->read([]);

    expect($rows)->toBe([]);
    VsScoreScreenshotExtractor::assertNeverPrompted();
});
