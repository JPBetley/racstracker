<?php

use App\Imports\Ocr\RosterNameMatcher;
use App\Models\Member;
use Illuminate\Database\Eloquent\Collection;

/**
 * Load the roster the way callers of the matcher are expected to.
 *
 * @return Collection<int, Member>
 */
function rosterWithAliases()
{
    return Member::with('aliases')->get();
}

beforeEach(function () {
    $this->member = Member::factory()->create(['name' => 'BrandNewName']);
    $this->member->recordAlias('Whiskey Brain');

    $this->matcher = new RosterNameMatcher;
});

test('a former name matches exactly even though the current name differs', function () {
    expect($this->matcher->suggest('Whiskey Brain', rosterWithAliases())?->id)
        ->toBe($this->member->id);
});

test('a former name matches through OCR noise', function () {
    expect($this->matcher->suggest('Whiskey Braln', rosterWithAliases())?->id)
        ->toBe($this->member->id);
});

test('the current name still matches', function () {
    expect($this->matcher->suggest('BrandNewName', rosterWithAliases())?->id)
        ->toBe($this->member->id);
});

test('an unrelated name matches nothing despite the aliases', function () {
    expect($this->matcher->suggest('Totally Unrelated Xyz', rosterWithAliases()))
        ->toBeNull();
});

test('a member with no aliases still matches on their name', function () {
    $plain = Member::factory()->create(['name' => 'Dark Cities']);

    expect($this->matcher->suggest('Dark Cities', rosterWithAliases())?->id)
        ->toBe($plain->id);
});

test('resolve reaches a member through a former name', function () {
    expect($this->matcher->resolve('whiskey brain', rosterWithAliases())?->id)
        ->toBe($this->member->id);
});

test('resolve refuses OCR noise against a former name', function () {
    expect($this->matcher->resolve('Whiskey Braln', rosterWithAliases()))
        ->toBeNull();
});
