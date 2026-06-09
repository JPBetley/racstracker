<?php

namespace App\Enums;

use App\Imports\Contracts\ImportWorkflow;
use App\Imports\Workflows\RosterImportWorkflow;

enum ImportType: string
{
    case Roster = 'roster';

    /**
     * Get the display label for the import type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Roster => 'Roster',
        };
    }

    /**
     * Resolve the workflow that drives this import type.
     */
    public function workflow(): ImportWorkflow
    {
        return match ($this) {
            self::Roster => new RosterImportWorkflow,
        };
    }
}
