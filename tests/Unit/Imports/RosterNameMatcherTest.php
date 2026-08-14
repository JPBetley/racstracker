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
