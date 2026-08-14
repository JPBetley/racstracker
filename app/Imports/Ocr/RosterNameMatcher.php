<?php

namespace App\Imports\Ocr;

use App\Models\Member;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
     * Every name a member has been known by is considered, not just their current
     * one, so a player who renamed still matches against screenshots and score
     * imports taken before the change. Eager load `aliases` to avoid N+1 queries.
     *
     * @param  Collection<int, Member>  $members
     */
    public function suggest(string $name, Collection $members): ?Member
    {
        $needle = mb_strtolower(trim($name));

        if ($needle === '') {
            return null;
        }

        $normalisedNeedle = $this->normalise($name);

        $best = null;
        $bestScore = 0.0;

        foreach ($members as $member) {
            foreach ($this->knownNames($member) as $known) {
                if ($known === $needle || $this->normalise($known) === $normalisedNeedle) {
                    return $member;
                }

                similar_text($normalisedNeedle, $this->normalise($known), $percent);

                if ($percent > $bestScore) {
                    $bestScore = $percent;
                    $best = $member;
                }
            }
        }

        return $bestScore >= self::THRESHOLD ? $best : null;
    }

    /**
     * Reduce a name to its comparable core.
     *
     * Players decorate their names with accents, CJK or Cyrillic characters, and
     * padding spaces, none of which survive a screenshot intact and none of which
     * identify anybody. Stripping them turns near misses like "Bęęfaronį" against
     * "Beefaroni", or "J E F F I" against "JEFFI", into exact matches instead of
     * scores that fall under the similarity threshold.
     *
     * Names that normalise away to nothing keep their lowercased original, so a
     * fully non-Latin name is still compared against something.
     */
    private function normalise(string $name): string
    {
        $lowered = mb_strtolower(trim($name));

        $ascii = Str::ascii($lowered);
        $stripped = preg_replace('/[^a-z0-9]/', '', $ascii) ?? '';

        return $stripped === '' ? preg_replace('/\s+/', '', $lowered) ?? $lowered : $stripped;
    }

    /**
     * Get every name a member is known by, lowercased for comparison.
     *
     * @return array<int, string>
     */
    private function knownNames(Member $member): array
    {
        $aliases = $member->exists ? $member->aliases->pluck('name') : collect();

        return collect([$member->name])
            ->concat($aliases)
            ->map(fn (string $name): string => mb_strtolower(trim($name)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
