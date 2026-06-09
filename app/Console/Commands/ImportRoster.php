<?php

namespace App\Console\Commands;

use App\Actions\Imports\StartImport;
use App\Enums\ImportType;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:roster {team : The team slug} {path? : Path to a roster JSON file}')]
#[Description('Queue a roster import for a team from a JSON file')]
class ImportRoster extends Command
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

        $path = $this->argument('path') ?? base_path('tests/stubs/team/members.json');

        if (! is_file($path)) {
            $this->error("No roster file found at [{$path}].");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($path), true);

        if (! is_array($payload) || ! isset($payload['members'])) {
            $this->error('The roster file must contain a "members" array.');

            return self::FAILURE;
        }

        $import = $startImport->handle($team, $creator, ImportType::Roster, $payload);

        $this->info("Queued roster import #{$import->id} for team [{$team->name}].");

        return self::SUCCESS;
    }
}
