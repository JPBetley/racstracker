<?php

namespace App\Jobs\Imports;

use App\Actions\Members\SyncAllianceRoster;
use App\LastWar\Contracts\LastWarApi;
use RuntimeException;

class SyncAllianceMembers extends ImportStep
{
    /**
     * Pull the alliance roster from the Last War API and reconcile it locally.
     *
     * Requests without a session key are served by a shared connection pool and
     * can take an unbounded amount of time, which is why this only ever runs on
     * the queue. Failures bubble so the import records them.
     */
    protected function run(): void
    {
        $team = $this->import->team;
        $allianceId = $this->import->payload['alliance_id'] ?? $team->allianceId();

        if (blank($allianceId)) {
            throw new RuntimeException(
                "Team [{$team->slug}] has no Last War alliance ID configured.",
            );
        }

        $members = app(LastWarApi::class)->allianceMembers($allianceId);

        $counts = app(SyncAllianceRoster::class)->handle($team, $members);

        $this->record([
            'alliance_id' => $allianceId,
            'total' => count($members),
            ...$counts,
        ]);
    }
}
