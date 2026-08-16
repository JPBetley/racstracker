<?php

namespace App\Actions\Imports;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Jobs\Imports\ParseVsScoreScreenshots;
use App\Models\Import;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;

class BeginVsScoreScreenshotImport
{
    /**
     * Record a VS score import from screenshots and queue the OCR parse.
     *
     * The parse runs on the queue and leaves the import awaiting review, where the
     * user matches each parsed row to a roster member before any score is written.
     *
     * The target week is frozen to an absolute date here rather than carried as an
     * offset, so the queued parse and the workflow step are independent of the user's
     * clock and timezone.
     *
     * @param  array<int, string>  $imagePaths  Stored paths to the uploaded screenshots, in order.
     */
    public function handle(Team $team, User $creator, CarbonInterface $weekStart, array $imagePaths): Import
    {
        $import = $team->imports()->create([
            'user_id' => $creator->id,
            'type' => ImportType::VsScores,
            'status' => ImportStatus::Pending,
            'payload' => [
                'week_start' => $weekStart->startOfWeek(CarbonInterface::MONDAY)->toDateString(),
                'screenshots' => array_values($imagePaths),
            ],
        ]);

        $import->markProcessing();

        ParseVsScoreScreenshots::dispatch($import);

        return $import;
    }
}
