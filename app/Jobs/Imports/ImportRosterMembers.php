<?php

namespace App\Jobs\Imports;

use App\Actions\Members\CreateMember;
use App\Enums\MemberPosition;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class ImportRosterMembers extends ImportStep
{
    /**
     * Create roster members from the import payload.
     *
     * Members whose name already exists on the team, or whose position would
     * breach a cap, are skipped and reported rather than failing the import.
     */
    protected function run(): void
    {
        $createMember = app(CreateMember::class);
        $team = $this->import->team;
        $rows = $this->import->payload['members'] ?? [];

        $created = 0;
        $skipped = [];

        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            $position = $row['position'] ?? null;

            if ($name === null || $position === null) {
                $skipped[] = ['name' => $name, 'reason' => __('Missing name or position.')];

                continue;
            }

            if ($team->roster()->where('name', $name)->exists()) {
                $skipped[] = ['name' => $name, 'reason' => __('Already on the roster.')];

                continue;
            }

            try {
                $createMember->handle($team, $name, MemberPosition::from($position));
                $created++;
            } catch (ValidationException $e) {
                $skipped[] = ['name' => $name, 'reason' => Arr::first(Arr::flatten($e->errors()))];
            }
        }

        $this->record([
            'total' => count($rows),
            'created' => $created,
            'skipped' => $skipped,
        ]);
    }
}
