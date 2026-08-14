<?php

namespace App\Actions\Imports;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Models\Import;
use App\Models\Team;
use App\Models\User;

class StartImport
{
    public function __construct(private DispatchImportWorkflow $dispatchWorkflow) {}

    /**
     * Record an import and dispatch its workflow to the queue.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(Team $team, User $creator, ImportType $type, array $payload): Import
    {
        $import = $team->imports()->create([
            'user_id' => $creator->id,
            'type' => $type,
            'status' => ImportStatus::Pending,
            'payload' => $payload,
        ]);

        $import->markProcessing();

        $this->dispatchWorkflow->handle($import);

        return $import;
    }
}
