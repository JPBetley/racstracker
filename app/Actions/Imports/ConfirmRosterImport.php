<?php

namespace App\Actions\Imports;

use App\Models\Import;

class ConfirmRosterImport
{
    public function __construct(private DispatchImportWorkflow $dispatchWorkflow) {}

    /**
     * Commit a reviewed roster draft, dispatching the import workflow.
     *
     * @param  array<int, array{name: string, position: string}>  $members  The reviewed, edited roster rows.
     */
    public function handle(Import $import, array $members): Import
    {
        $import->payload = array_merge($import->payload ?? [], ['members' => array_values($members)]);
        $import->save();

        $import->markProcessing();

        $this->dispatchWorkflow->handle($import);

        return $import;
    }
}
