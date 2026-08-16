<?php

namespace App\Actions\Imports;

use App\Models\Import;
use Carbon\CarbonInterface;

class ConfirmVsScoreImport
{
    public function __construct(private DispatchImportWorkflow $dispatchWorkflow) {}

    /**
     * Commit a reviewed VS score draft, dispatching the import workflow.
     *
     * The target week is rewritten here as well as at upload time, so a reviewer who
     * realises they picked the wrong week can correct it without re-uploading.
     *
     * @param  array<int, array{member_id: ?int, name: string, points: int}>  $scores  The reviewed, member-matched rows.
     */
    public function handle(Import $import, CarbonInterface $weekStart, array $scores): Import
    {
        $import->payload = array_merge($import->payload ?? [], [
            'week_start' => $weekStart->startOfWeek(CarbonInterface::MONDAY)->toDateString(),
            'scores' => array_values($scores),
        ]);
        $import->save();

        $import->markProcessing();

        $this->dispatchWorkflow->handle($import);

        return $import;
    }
}
