<?php

namespace App\Enums;

use App\Imports\Contracts\ImportWorkflow;
use App\Imports\Workflows\AllianceRosterImportWorkflow;
use App\Imports\Workflows\RosterImportWorkflow;

enum ImportType: string
{
    case Roster = 'roster';
    case AllianceRoster = 'alliance_roster';

    /**
     * Get the display label for the import type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Roster => 'Roster',
            self::AllianceRoster => 'Alliance Roster',
        };
    }

    /**
     * Resolve the workflow that drives this import type.
     */
    public function workflow(): ImportWorkflow
    {
        return match ($this) {
            self::Roster => new RosterImportWorkflow,
            self::AllianceRoster => new AllianceRosterImportWorkflow,
        };
    }
}
