<?php

namespace App\Jobs\Imports;

use App\Imports\Ocr\RosterNameMatcher;
use App\LastWar\Game\VsRankingReader;
use RuntimeException;

class FetchGameVsScores extends ImportStep
{
    /**
     * Read this week's VS ranking from the game and stage it as reviewed score rows.
     *
     * Rows are written in the same shape a confirmed screenshot import produces, so
     * the following ImportVsScores step records them exactly as it would those.
     * Players are matched by their game uid, then by an exact name or alias among
     * members not yet linked to a uid; anyone else is left unmatched and reported.
     */
    protected function run(): void
    {
        $team = $this->import->team;
        $account = $team->gameAccount;
        $allianceId = $this->import->payload['alliance_id'] ?? $team->allianceId();

        if ($account === null) {
            throw new RuntimeException("Team [{$team->slug}] has no bound game account.");
        }

        if (blank($allianceId)) {
            throw new RuntimeException("Team [{$team->slug}] has no Last War alliance ID configured.");
        }

        $ranking = app(VsRankingReader::class)->weeklyScores($account, $allianceId);

        $roster = $team->roster()->with('aliases')->get();
        $byUid = $roster->whereNotNull('uid')->keyBy('uid');
        $unlinked = $roster->whereNull('uid')->values();
        $matcher = app(RosterNameMatcher::class);

        $scores = array_map(fn (array $row): array => [
            'member_id' => ($byUid->get($row['uid']) ?? $matcher->resolve($row['name'], $unlinked))?->id,
            'name' => $row['name'],
            'points' => $row['points'],
        ], $ranking);

        $this->import->update(['payload' => [
            ...$this->import->payload,
            'alliance_id' => $allianceId,
            'week_start' => $this->import->payload['week_start'] ?? VsRankingReader::weekStartAt(now())->toDateString(),
            'scores' => $scores,
        ]]);

        $this->record([
            'alliance_id' => $allianceId,
            'fetched' => count($scores),
        ]);
    }
}
