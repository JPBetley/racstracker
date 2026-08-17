<?php

namespace App\Actions\Teams;

use App\Models\Team;

class UpdateTeamSettings
{
    /**
     * Save the alliance-wide expectations recorded against a team.
     *
     * A requirement of zero means "no requirement", so the columns are stored
     * unsigned and default to zero rather than being nullable.
     */
    public function handle(
        Team $team,
        int $vsMinimum,
        int $trainVsRequirement,
        bool $trainDesertStormRequirement,
    ): Team {
        $team->update([
            'vs_minimum' => $vsMinimum,
            'train_vs_requirement' => $trainVsRequirement,
            'train_desert_storm_requirement' => $trainDesertStormRequirement,
        ]);

        return $team;
    }
}
