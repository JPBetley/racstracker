<?php

namespace App\Imports\Contracts;

use App\Jobs\Imports\ImportStep;
use App\Models\Import;

interface ImportWorkflow
{
    /**
     * Get the ordered steps that make up this import workflow.
     *
     * @return array<int, ImportStep>
     */
    public function steps(Import $import): array;
}
