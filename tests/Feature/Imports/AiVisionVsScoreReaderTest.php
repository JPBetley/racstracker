<?php

use App\Ai\Agents\VsScoreScreenshotExtractor;
use App\Imports\Ocr\AiVisionVsScoreScreenshotReader;

test('it parses points, cleans names, and de-duplicates the rows returned by the vision agent', function () {
    // A closure fake answers every request the same way, so this holds whether the
    // reader sends one request (cloud) or one per screenshot (local Ollama).
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

    $rows = (new AiVisionVsScoreScreenshotReader)->read([
        base_path('tests/stubs/vs/vs-1.png'),
        base_path('tests/stubs/vs/vs-2.png'),
    ]);

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

    $rows = (new AiVisionVsScoreScreenshotReader)->read([base_path('tests/stubs/vs/vs-1.png')]);

    expect(array_column($rows, 'name'))->toBe(['666', 'epoman46', 'Good Luck', 'Beefaroni']);
});

test('it sends every screenshot to the configured Claude model in a single request', function () {
    config()->set('vs.ocr.provider', 'anthropic');
    config()->set('vs.ocr.model', 'claude-opus-5');

    VsScoreScreenshotExtractor::fake(fn () => ['rows' => []]);

    (new AiVisionVsScoreScreenshotReader)->read([
        base_path('tests/stubs/vs/vs-1.png'),
        base_path('tests/stubs/vs/vs-2.png'),
    ]);

    VsScoreScreenshotExtractor::assertPrompted(
        fn ($prompt) => $prompt->model === 'claude-opus-5'
            && $prompt->provider->driver() === 'anthropic'
            && $prompt->attachments->count() === 2
    );
});

test('it reads one screenshot per request on a local model', function () {
    config()->set('vs.ocr.provider', 'ollama');
    config()->set('vs.ocr.model', 'llama3.2-vision');

    VsScoreScreenshotExtractor::fake(fn () => ['rows' => []]);

    (new AiVisionVsScoreScreenshotReader)->read([
        base_path('tests/stubs/vs/vs-1.png'),
        base_path('tests/stubs/vs/vs-2.png'),
    ]);

    VsScoreScreenshotExtractor::assertPrompted(fn ($prompt) => $prompt->attachments->count() === 1);
});

test('it returns nothing when given no screenshots and never calls the model', function () {
    VsScoreScreenshotExtractor::fake()->preventStrayPrompts();

    $rows = (new AiVisionVsScoreScreenshotReader)->read([]);

    expect($rows)->toBe([]);
    VsScoreScreenshotExtractor::assertNeverPrompted();
});
