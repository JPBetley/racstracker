<?php

namespace App\Jobs\Imports;

use App\Actions\Members\SyncAllianceRoster;
use App\Imports\Ocr\RosterNameMatcher;
use App\Jobs\Imports\Concerns\RetriesGameFailures;
use App\LastWar\Game\AllianceReader;
use App\LastWar\Game\Exceptions\GameClientException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class FetchGameVsScores extends ImportStep
{
    use RetriesGameFailures;

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
     * Refresh the roster from the game, then stage this week's VS ranking as reviewed score rows.
     *
     * One login reads both the alliance member list and the ranking. The member list
     * is synced first so players who joined since the last run exist before their
     * scores are matched. A roster sync the game's list cannot pass (an empty list, one
     * that breaks the position caps, or names that collide, such as two members
     * swapping names) is recorded as `roster_error` and the scores
     * are matched against the roster as it stands, rather than losing the night's scores.
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

        $weekStart = $this->import->payload['week_start'] ?? AllianceReader::weekStartAt(now())->toDateString();

        if ($weekStart !== AllianceReader::weekStartAt(now())->toDateString()) {
            $this->fail(new RuntimeException("The VS week of {$weekStart} has ended; the game now ranks a newer week."));

            return;
        }

        try {
            ['members' => $members, 'scores' => $ranking] = app(AllianceReader::class)->read($account, $allianceId);
        } catch (GameClientException $e) {
            if (! self::isWorthRetrying($e)) {
                $this->fail($e);

                return;
            }

            throw $e;
        }

        try {
            $this->record(['roster' => app(SyncAllianceRoster::class)->handle($team, $members)]);
        } catch (InvalidArgumentException|ValidationException|UniqueConstraintViolationException $e) {
            $this->record(['roster_error' => $e->getMessage()]);
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
}
