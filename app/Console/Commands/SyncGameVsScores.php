<?php

namespace App\Console\Commands;

use App\Actions\Imports\StartImport;
use App\Enums\ImportType;
use App\LastWar\Game\VsRankingReader;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:game-vs-scores')]
#[Description("Queue an import of this week's VS scores from the game for every team with a bound game account")]
class SyncGameVsScores extends Command
{
    /**
     * Queue the nightly VS score refresh for every team that can sync from the game.
     *
     * Each run overwrites the current week's scores with the running weekly totals,
     * so the last run before the Monday reset leaves the week's final standings.
     * Only teams with an `alliance_id` of their own are swept, as with the roster sync.
     */
    public function handle(StartImport $startImport): int
    {
        $teams = Team::whereNotNull('alliance_id')->whereHas('gameAccount')->get();

        if ($teams->isEmpty()) {
            $this->info('No teams have a bound game account and alliance ID.');

            return self::SUCCESS;
        }

        $weekStart = VsRankingReader::weekStartAt(now())->toDateString();
        $queued = 0;

        foreach ($teams as $team) {
            $creator = $team->owner();

            if ($creator === null) {
                $this->warn("Skipped team [{$team->slug}]: no owner to attribute the import to.");

                continue;
            }

            $startImport->handle($team, $creator, ImportType::GameVsScores, [
                'alliance_id' => $team->alliance_id,
                'week_start' => $weekStart,
            ]);

            $queued++;
        }

        $this->info("Queued {$queued} game VS score import(s) for the week of {$weekStart}.");

        return self::SUCCESS;
    }
}
