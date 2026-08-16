<?php

namespace App\Imports\Workflows;

use App\Imports\Contracts\ImportWorkflow;
use App\Jobs\Imports\ImportStep;
use App\Jobs\Imports\ImportVsScores;
use App\Models\Import;

class VsScoreImportWorkflow implements ImportWorkflow
{
    /**
     * Get the ordered steps that record a week of VS scores.
     *
     * @return array<int, ImportStep>
     */
    public function steps(Import $import): array
    {
        return [
            new ImportVsScores($import),
        ];
    }
}
