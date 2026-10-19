<?php

namespace App\Jobs\Imports;

use App\Imports\Ocr\RosterNameMatcher;
use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\Exceptions\GateServerException;
use App\LastWar\Game\Exceptions\LoginRejectedException;
use App\LastWar\Game\Exceptions\SessionEndedException;
use App\LastWar\Game\VsRankingReader;
use RuntimeException;

class FetchGameVsScores extends ImportStep
{
    /**
     * Every attempt is a fresh login that kicks whoever is playing the account, so
     * only one spaced-out retry is allowed, and only for failures that might pass.
     */
    public int $tries = 2;

    /**
     * Wait before retrying, in seconds per attempt.
     *
     * @var array<int, int>
     */
    public array $backoff = [300];

    /**
     * Read this week's VS ranking from the game and stage it as reviewed score rows.
     *
     * Rows are written in the same shape a confirmed screenshot import produces, so
     * the following ImportVsScores step records them exactly as it would those.
     * Players are matched by their game uid, then by an exact name or alias among
     * members not yet linked to a uid; anyone else is left unmatched and reported.
     *
     * The game only ranks the week in progress, so a run that starts after the week
     * it was queued for has rolled over fails instead of recording the new week's
     * totals over the old week's final ones.
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

        $weekStart = $this->import->payload['week_start'] ?? VsRankingReader::weekStartAt(now())->toDateString();

        if ($weekStart !== VsRankingReader::weekStartAt(now())->toDateString()) {
            $this->fail(new RuntimeException("The VS week of {$weekStart} has ended; the game now ranks a newer week."));

            return;
        }

        try {
            $ranking = app(VsRankingReader::class)->weeklyScores($account, $allianceId);
        } catch (GameClientException $e) {
            if (! self::isWorthRetrying($e)) {
                $this->fail($e);

                return;
            }

            throw $e;
        }

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
            'week_start' => $weekStart,
            'scores' => $scores,
        ]]);

        $this->record([
            'alliance_id' => $allianceId,
            'fetched' => count($scores),
        ]);
    }

    /**
     * Whether a retry could get a different answer than this failure.
     *
     * Dead credentials and rejected logins fail the same way every time, and the
     * owner logging back in is no reason to kick them off again.
     */
    private static function isWorthRetrying(GameClientException $e): bool
    {
        return match (true) {
            $e instanceof GateServerException => ! $e->requiresReauth(),
            $e instanceof LoginRejectedException => false,
            $e instanceof SessionEndedException => $e->push !== 'push.user.off',
            default => true,
        };
    }
}
