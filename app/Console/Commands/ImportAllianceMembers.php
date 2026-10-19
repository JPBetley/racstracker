<?php

namespace App\Console\Commands;

use App\Actions\Imports\StartImport;
use App\Enums\ImportType;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:alliance {team : The team slug} {--alliance= : Override the alliance ID to import from}')]
#[Description('Queue an alliance roster import from the game')]
class ImportAllianceMembers extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(StartImport $startImport): int
    {
        $team = Team::where('slug', $this->argument('team'))->first();

        if ($team === null) {
            $this->error("No team found with slug [{$this->argument('team')}].");

            return self::FAILURE;
        }

        $creator = $team->owner();

        if ($creator === null) {
            $this->error('The team has no owner to attribute the import to.');

            return self::FAILURE;
        }

        $allianceId = $this->option('alliance') ?? $team->allianceId();

        if (blank($allianceId)) {
            $this->error("Team [{$team->slug}] has no alliance ID.");
            $this->line('Set one on the team, pass --alliance=, or set LASTWAR_ALLIANCE_ID.');

            return self::FAILURE;
        }

        if ($team->gameAccount === null) {
            $this->error("Team [{$team->slug}] has no bound game account.");
            $this->line("Bind one with: php artisan lastwar:game-bind {$team->slug}");

            return self::FAILURE;
        }

        $import = $startImport->handle($team, $creator, ImportType::AllianceRoster, [
            'alliance_id' => $allianceId,
        ]);

        $this->info("Queued alliance roster import #{$import->id} for team [{$team->name}].");

        return self::SUCCESS;
    }
}
