<?php

namespace App\Enums;

use App\Imports\Contracts\ImportWorkflow;
use App\Imports\Workflows\AllianceRosterImportWorkflow;
use App\Imports\Workflows\RosterImportWorkflow;
use App\Imports\Workflows\VsScoreImportWorkflow;

enum ImportType: string
{
    case Roster = 'roster';
    case AllianceRoster = 'alliance_roster';
    case VsScores = 'vs_scores';

    /**
     * Get the display label for the import type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Roster => 'Roster',
            self::AllianceRoster => 'Alliance Roster',
            self::VsScores => 'VS Scores',
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
            self::VsScores => new VsScoreImportWorkflow,
        };
    }
}
