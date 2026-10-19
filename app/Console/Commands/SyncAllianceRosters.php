<?php

namespace App\Console\Commands;

use App\Actions\Imports\StartImport;
use App\Enums\ImportType;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:alliance-rosters')]
#[Description('Queue an alliance roster import from the game for every team with a bound game account')]
class SyncAllianceRosters extends Command
{
    /**
     * Queue a roster refresh from the game for every team configured to sync.
     *
     * The nightly VS import already refreshes each roster in the same login, so this
     * is not scheduled; it remains for running by hand.
     *
     * Only teams with a bound game account and an `alliance_id` of their own are swept. The
     * `services.lastwar.alliance_id` fallback is deliberately ignored here: it would
     * point every unconfigured team — personal teams included — at the same alliance.
     */
    public function handle(StartImport $startImport): int
    {
        $teams = Team::whereNotNull('alliance_id')->whereHas('gameAccount')->get();

        if ($teams->isEmpty()) {
            $this->info('No teams have a bound game account and alliance ID.');

            return self::SUCCESS;
        }

        $queued = 0;

        foreach ($teams as $team) {
            $creator = $team->owner();

            if ($creator === null) {
                $this->warn("Skipped team [{$team->slug}]: no owner to attribute the import to.");

                continue;
            }

            $startImport->handle($team, $creator, ImportType::AllianceRoster, [
                'alliance_id' => $team->alliance_id,
            ]);

            $queued++;
        }

        $this->info("Queued {$queued} alliance roster import(s).");

        return self::SUCCESS;
    }
}
