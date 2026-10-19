<?php

namespace App\Imports\Workflows;

use App\Imports\Contracts\ImportWorkflow;
use App\Jobs\Imports\FetchGameVsScores;
use App\Jobs\Imports\ImportStep;
use App\Jobs\Imports\ImportVsScores;
use App\Models\Import;

class GameVsScoreImportWorkflow implements ImportWorkflow
{
    /**
     * Get the ordered steps that record this week's VS scores read from the game.
     *
     * @return array<int, ImportStep>
     */
    public function steps(Import $import): array
    {
        return [
            new FetchGameVsScores($import),
            new ImportVsScores($import),
        ];
    }
}
