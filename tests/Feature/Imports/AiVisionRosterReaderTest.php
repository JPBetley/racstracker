<?php

use App\Ai\Agents\RosterScreenshotExtractor;
use App\Imports\Ocr\AiVisionRosterScreenshotReader;

test('it cleans and de-duplicates the members returned by the vision agent', function () {
    RosterScreenshotExtractor::fake(fn () => [
        'members' => [
            ['name' => 'I am Mr Yeti', 'position' => 'R5'],
            ['name' => 'Whiskey Brain', 'position' => 'R4'],
            ['name' => 'whiskey brain', 'position' => 'R4'], // duplicate (case-insensitive)
            ['name' => '木木666', 'position' => 'R1'],          // non-Latin stripped to "666"
            ['name' => '', 'position' => 'R3'],                 // empty name dropped
            ['name' => 'Bad Position', 'position' => 'R9'],     // invalid position dropped
        ],
    ]);

    $rows = (new AiVisionRosterScreenshotReader)->read([
        base_path('tests/stubs/team/team-1.png'),
        base_path('tests/stubs/team/team-2.png'),
    ]);

    expect($rows)->toBe([
        ['name' => 'I am Mr Yeti', 'position' => 'R5'],
        ['name' => 'Whiskey Brain', 'position' => 'R4'],
        ['name' => '666', 'position' => 'R1'],
    ]);

    RosterScreenshotExtractor::assertPrompted(fn ($prompt) => $prompt->contains('roster'));
});

test('it sends every screenshot to the configured Claude model in a single request', function () {
    // Deliberately not a real model name: it cannot collide with the config default,
    // so the assertion proves the configured value is what actually reaches the prompt.
    config()->set('roster.ocr.model', 'claude-model-under-test');

    RosterScreenshotExtractor::fake(fn () => ['members' => []]);

    (new AiVisionRosterScreenshotReader)->read([
        base_path('tests/stubs/team/team-1.png'),
        base_path('tests/stubs/team/team-2.png'),
    ]);

    // The provider is pinned to Anthropic in the reader; only the model is configurable.
    RosterScreenshotExtractor::assertPrompted(
        fn ($prompt) => $prompt->model === 'claude-model-under-test'
            && $prompt->provider->driver() === 'anthropic'
            && $prompt->attachments->count() === 2
    );
});

test('it returns nothing when given no screenshots and never calls the model', function () {
    RosterScreenshotExtractor::fake()->preventStrayPrompts();

    $rows = (new AiVisionRosterScreenshotReader)->read([]);

    expect($rows)->toBe([]);
    RosterScreenshotExtractor::assertNeverPrompted();
});
