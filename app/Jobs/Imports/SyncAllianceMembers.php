<?php

namespace App\Jobs\Imports;

use App\Actions\Members\SyncAllianceRoster;
use App\Jobs\Imports\Concerns\RetriesGameFailures;
use App\LastWar\Game\AllianceReader;
use App\LastWar\Game\Exceptions\GameClientException;
use RuntimeException;

class SyncAllianceMembers extends ImportStep
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
     * Pull the alliance member list from the game and reconcile the roster locally.
     *
     * The read logs in as the team's bound game account, which kicks it off any other
     * device, so this only ever runs on the queue. Failures bubble so the import
     * records them.
     */
    protected function run(): void
    {
        $team = $this->import->team;
        $account = $team->gameAccount;
        $allianceId = $this->import->payload['alliance_id'] ?? $team->allianceId();

        if (blank($allianceId)) {
            throw new RuntimeException(
                "Team [{$team->slug}] has no Last War alliance ID configured.",
            );
        }

        if ($account === null) {
            throw new RuntimeException(
                "Team [{$team->slug}] has no bound game account; run lastwar:game-bind first.",
            );
        }

        try {
            $members = app(AllianceReader::class)->membersOnly($account, $allianceId);
        } catch (GameClientException $e) {
            if (! self::isWorthRetrying($e)) {
                $this->fail($e);

                return;
            }

            throw $e;
        }

        $counts = app(SyncAllianceRoster::class)->handle($team, $members);

        $this->record([
            'alliance_id' => $allianceId,
            'total' => count($members),
            ...$counts,
        ]);
    }
}
