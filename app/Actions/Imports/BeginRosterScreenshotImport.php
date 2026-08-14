<?php

namespace App\Actions\Imports;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Jobs\Imports\ParseRosterScreenshots;
use App\Models\Import;
use App\Models\Team;
use App\Models\User;

class BeginRosterScreenshotImport
{
    /**
     * Record a roster import from screenshots and queue the OCR parse.
     *
     * The parse runs on the queue and leaves the import awaiting review, where the
     * user corrects the draft before the roster is actually written.
     *
     * @param  array<int, string>  $imagePaths  Stored paths to the uploaded screenshots, in order.
     */
    public function handle(Team $team, User $creator, array $imagePaths): Import
    {
        $import = $team->imports()->create([
            'user_id' => $creator->id,
            'type' => ImportType::Roster,
            'status' => ImportStatus::Pending,
            'payload' => ['screenshots' => array_values($imagePaths)],
        ]);

        $import->markProcessing();

        ParseRosterScreenshots::dispatch($import);

        return $import;
    }
}
