<?php

namespace App\Imports\Workflows;

use App\Imports\Contracts\ImportWorkflow;
use App\Jobs\Imports\ImportStep;
use App\Jobs\Imports\SyncAllianceMembers;
use App\Models\Import;

class AllianceRosterImportWorkflow implements ImportWorkflow
{
    /**
     * Get the ordered steps that sync a roster from the Last War API.
     *
     * @return array<int, ImportStep>
     */
    public function steps(Import $import): array
    {
        return [
            new SyncAllianceMembers($import),
        ];
    }
}
