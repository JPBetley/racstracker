<?php

namespace App\Actions\Conductors;

use App\Models\ConductorAssignment;

class DeleteConductorAssignment
{
    /**
     * Remove a day from the conductor history.
     */
    public function handle(ConductorAssignment $assignment): void
    {
        $assignment->delete();
    }
}
