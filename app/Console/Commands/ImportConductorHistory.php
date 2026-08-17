<?php

namespace App\Console\Commands;

use App\Actions\Conductors\CreateConductorAssignment;
use App\Actions\Conductors\UpdateConductorAssignment;
use App\Imports\Ocr\RosterNameMatcher;
use App\Models\ConductorAssignment;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('import:conductor-history {team : The team slug} {path? : Path to the history CSV} {--dry-run : Report what would change without writing}')]
#[Description('Backfill historical train conductor assignments for a team from a CSV')]
class ImportConductorHistory extends Command
{
    /**
     * Backfill the hand-kept record of who conducted the train each day.
     *
     * Intended to be run once per environment on initial setup, but written to be
     * safe to repeat: a day already recorded is corrected or left alone rather than
     * colliding with the unique index over [team_id, assigned_on].
     *
     * Names in the file were typed by hand over months, so some are older in-game
     * names and some belong to players who have since left. A row that reaches no
     * member is reported and skipped — never turned into a new member, which would
     * pollute the roster with people who are no longer in the alliance.
     *
     * MVP is a weekly award that historically landed on the Sunday conductor, so
     * the flag is derived from the day rather than carried in the file.
     */
    public function handle(RosterNameMatcher $matcher, CreateConductorAssignment $createAssignment, UpdateConductorAssignment $updateAssignment): int
    {
        $slug = (string) $this->argument('team');
        $team = Team::where('slug', $slug)->first();

        if ($team === null) {
            $this->error("No team found with slug [{$slug}].");

            return self::FAILURE;
        }

        $path = (string) ($this->argument('path') ?? database_path('data/conductor-history.csv'));

        if (! is_file($path)) {
            $this->error("No conductor history file found at [{$path}].");

            return self::FAILURE;
        }

        $rows = $this->readCsv($path);

        if ($rows === null) {
            $this->error("The conductor history file at [{$path}] could not be read.");

            return self::FAILURE;
        }

        /**
         * Inactive members are deliberately included. Someone who conducted the
         * train in May and has since left the alliance is deactivated rather than
         * deleted, and their history still belongs to them.
         */
        $members = $team->roster()->with('aliases')->get();

        /**
         * Days already on record, keyed through the model's date cast rather than
         * matched in SQL. The cast writes a full timestamp, so comparing a bare
         * `Y-m-d` in the database only lines up on some drivers.
         */
        $existing = $team->conductorAssignments()->get()
            ->keyBy(fn (ConductorAssignment $assignment): string => $assignment->assigned_on->toDateString());

        $dryRun = (bool) $this->option('dry-run');

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $mvp = 0;

        /** @var array<int, array{0: string, 1: string, 2: string}> $skipped */
        $skipped = [];

        DB::transaction(function () use ($rows, $members, $existing, $matcher, $createAssignment, $updateAssignment, $team, $dryRun, &$created, &$updated, &$unchanged, &$mvp, &$skipped): void {
            foreach ($rows as [$date, $name, $sourceName]) {
                $assignedOn = $this->parseDate($date);

                if ($assignedOn === null || $name === '') {
                    $skipped[] = [$date, $name, __('Malformed row.')];

                    continue;
                }

                $member = $matcher->resolve($name, $members);

                if ($member === null) {
                    $skipped[] = [$assignedOn->toDateString(), $name, __('No matching member.')];

                    continue;
                }

                $isMvp = $assignedOn->isSunday();
                $assignment = $existing->get($assignedOn->toDateString());

                if ($assignment === null) {
                    $created++;
                    $mvp += (int) $isMvp;

                    if (! $dryRun) {
                        $existing->put($assignedOn->toDateString(), $createAssignment->handle($team, $member, $assignedOn, $isMvp));
                    }
                } elseif ($assignment->member_id === $member->id && $assignment->is_mvp === $isMvp) {
                    $unchanged++;
                } else {
                    $updated++;
                    $mvp += (int) $isMvp;

                    if (! $dryRun) {
                        $updateAssignment->handle($assignment, $member, $assignedOn, $isMvp);
                    }
                }

                if (! $dryRun) {
                    $member->recordAlias($sourceName);
                }
            }
        });

        $this->report($created, $updated, $unchanged, $mvp, $skipped, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Read the history file into `[date, name, source name]` rows.
     *
     * The header line is dropped, and short rows are padded rather than rejected so
     * a trailing empty `source_name` column may be omitted.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>|null
     */
    private function readCsv(string $path): ?array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return null;
        }

        $rows = [];

        while (($fields = fgetcsv($handle, escape: '')) !== false) {
            if ($fields === [null]) {
                continue;
            }

            $rows[] = [
                trim((string) ($fields[0] ?? '')),
                trim((string) ($fields[1] ?? '')),
                trim((string) ($fields[2] ?? '')),
            ];
        }

        fclose($handle);

        if ($rows !== [] && $rows[0][0] === 'assigned_on') {
            array_shift($rows);
        }

        return $rows;
    }

    /**
     * Read a `Y-m-d` date, returning null when the value is not one.
     */
    private function parseDate(string $value): ?CarbonImmutable
    {
        if (! CarbonImmutable::hasFormat($value, 'Y-m-d')) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay();
    }

    /**
     * Print the tally, followed by every row that reached no member.
     *
     * Skips are expected rather than failures — the list includes players who left
     * the alliance long ago — so they are reported and the command still succeeds.
     *
     * @param  array<int, array{0: string, 1: string, 2: string}>  $skipped
     */
    private function report(int $created, int $updated, int $unchanged, int $mvp, array $skipped, bool $dryRun): void
    {
        $verb = $dryRun ? 'Dry run: would import' : 'Imported';

        $this->info("{$verb} {$created} new, {$updated} corrected, {$unchanged} already current assignment(s).");
        $this->line("{$mvp} of those fell on a Sunday and are flagged MVP.");

        if ($skipped === []) {
            return;
        }

        $this->newLine();
        $this->warn(count($skipped).' row(s) skipped:');
        $this->table(['Date', 'Name', 'Reason'], $skipped);
    }
}
