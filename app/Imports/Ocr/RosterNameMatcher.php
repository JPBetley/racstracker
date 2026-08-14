<?php

namespace App\Imports\Ocr;

use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * Suggests the existing roster member an OCR-parsed name most likely refers to.
 *
 * OCR mangles stylised usernames, so on a re-import the parsed text rarely matches
 * a stored member exactly. This finds the closest existing member by string
 * similarity so the review UI can offer "did you mean …?" and avoid creating
 * garbled duplicates.
 */
class RosterNameMatcher
{
    /**
     * Minimum similarity (0-100) for a parsed name to be considered a match.
     */
    private const THRESHOLD = 80.0;

    /**
     * Find the existing member whose name best matches the parsed name.
     *
     * @param  Collection<int, Member>  $members
     */
    public function suggest(string $name, Collection $members): ?Member
    {
        $needle = mb_strtolower(trim($name));

        if ($needle === '') {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($members as $member) {
            if (mb_strtolower($member->name) === $needle) {
                return $member;
            }

            similar_text($needle, mb_strtolower($member->name), $percent);

            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = $member;
            }
        }

        return $bestScore >= self::THRESHOLD ? $best : null;
    }
}
