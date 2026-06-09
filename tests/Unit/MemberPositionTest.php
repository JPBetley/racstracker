<?php

use App\Enums\MemberPosition;

test('positions expose their per-team caps', function () {
    expect(MemberPosition::R5->maxPerTeam())->toBe(1)
        ->and(MemberPosition::R4->maxPerTeam())->toBe(10)
        ->and(MemberPosition::R3->maxPerTeam())->toBeNull()
        ->and(MemberPosition::R2->maxPerTeam())->toBeNull()
        ->and(MemberPosition::R1->maxPerTeam())->toBeNull();
});

test('options expose every position for select inputs', function () {
    expect(MemberPosition::options())->toBe([
        ['value' => 'R5', 'label' => 'R5'],
        ['value' => 'R4', 'label' => 'R4'],
        ['value' => 'R3', 'label' => 'R3'],
        ['value' => 'R2', 'label' => 'R2'],
        ['value' => 'R1', 'label' => 'R1'],
    ]);
});
