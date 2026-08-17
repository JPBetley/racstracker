<?php

use App\Imports\Ocr\RosterNameMatcher;
use App\Models\Member;

dataset('roster', fn () => [collect([
    new Member(['name' => 'Whiskey Brain']),
    new Member(['name' => 'Dark Cities']),
])]);

test('it matches an exact name regardless of case', function ($roster) {
    expect((new RosterNameMatcher)->suggest('whiskey brain', $roster)?->name)
        ->toBe('Whiskey Brain');
})->with('roster');

test('it suggests the closest member for a near miss', function ($roster) {
    expect((new RosterNameMatcher)->suggest('Whiskey Braln', $roster)?->name)
        ->toBe('Whiskey Brain');
})->with('roster');

test('it returns null when nothing is close enough', function ($roster) {
    expect((new RosterNameMatcher)->suggest('Totally Unrelated Xyz', $roster))
        ->toBeNull();
})->with('roster');

test('it matches through decoration that a screenshot cannot capture', function (string $stored, string $parsed) {
    $roster = collect([new Member(['name' => $stored])]);

    expect((new RosterNameMatcher)->suggest($parsed, $roster)?->name)->toBe($stored);
})->with([
    'accented letters' => ['Beefaroni', 'Bęęfaronį'],
    'cjk prefix' => ['666', '木木666'],
    'spaced out letters' => ['JEFFI', 'J E F F I'],
    'hangul suffix' => ['Good Luck', 'Good Luck굿럭'],
    'cyrillic lookalike' => ['Repoman46', 'яepoman46'],
    'transposed letters' => ['Vicinencski', 'Vicinenscki'],
]);

test('it still refuses genuinely different names after normalisation', function (string $stored, string $parsed) {
    $roster = collect([new Member(['name' => $stored])]);

    expect((new RosterNameMatcher)->suggest($parsed, $roster))->toBeNull();
})->with([
    'unrelated names' => ['Stoney71', 'Signalten'],
    'different players' => ['Checkm8Vixxen', 'Chrixx11'],
    'shared suffix only' => ['TacoSteve23', 'TeflonRon23'],
]);

test('it compares a fully non-latin name against something', function () {
    $roster = collect([new Member(['name' => '木木木'])]);

    expect((new RosterNameMatcher)->suggest('木木木', $roster)?->name)->toBe('木木木');
});

test('resolve matches a name through decoration and spacing', function (string $stored, string $given) {
    $roster = collect([new Member(['name' => $stored])]);

    expect((new RosterNameMatcher)->resolve($given, $roster)?->name)->toBe($stored);
})->with([
    'different case' => ['Irishjen536', 'IrishJen536'],
    'padded spacing' => ['Sunset  Ryder  OG', 'Sunset Ryder OG'],
    'hangul prefix' => ['쏠namisol', 'namisol'],
    'spaced out letters' => ['J E F F I', 'JEFFI'],
]);

test('resolve refuses a merely similar name that suggest would claim', function ($roster) {
    $matcher = new RosterNameMatcher;

    // An unattended backfill has nobody to confirm a near miss, so it must record
    // nothing rather than the closest guess.
    expect($matcher->suggest('Whiskey Braln', $roster)?->name)->toBe('Whiskey Brain')
        ->and($matcher->resolve('Whiskey Braln', $roster))->toBeNull();
})->with('roster');

test('resolve ignores a blank name', function ($roster) {
    expect((new RosterNameMatcher)->resolve('   ', $roster))->toBeNull();
})->with('roster');
