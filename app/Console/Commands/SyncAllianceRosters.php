<?php

namespace App\Console\Commands;

use App\Actions\Imports\StartImport;
use App\Enums\ImportType;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:alliance-rosters')]
#[Description('Queue an alliance roster import for every team with a Last War alliance ID')]
class SyncAllianceRosters extends Command
{
    /**
     * Queue the nightly roster refresh for every team configured to sync.
     *
     * Only teams with an `alliance_id` of their own are swept. The
     * `services.lastwar.alliance_id` fallback is deliberately ignored here: it would
     * point every unconfigured team — personal teams included — at the same alliance.
     */
    public function handle(StartImport $startImport): int
    {
        if (blank(config('services.lastwar.key'))) {
            $this->error('No Last War API key configured. Set LASTWAR_API_KEY.');

            return self::FAILURE;
        }

        $teams = Team::whereNotNull('alliance_id')->get();

        if ($teams->isEmpty()) {
            $this->info('No teams have an alliance ID configured.');

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
