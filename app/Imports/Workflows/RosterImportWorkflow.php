<?php

namespace App\Imports\Workflows;

use App\Imports\Contracts\ImportWorkflow;
use App\Jobs\Imports\ImportRosterMembers;
use App\Jobs\Imports\ImportStep;
use App\Models\Import;

class RosterImportWorkflow implements ImportWorkflow
{
    /**
     * Get the ordered steps that import an alliance roster.
     *
     * @return array<int, ImportStep>
     */
    public function steps(Import $import): array
    {
        return [
            new ImportRosterMembers($import),
        ];
    }
}
