<?php

namespace App\Actions\Imports;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Jobs\Imports\CompleteImport;
use App\Models\Import;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Throwable;

class StartImport
{
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

        $importId = $import->id;

        Bus::chain([
            ...$type->workflow()->steps($import),
            new CompleteImport($import),
        ])
            ->catch(fn (Throwable $e) => app(FailImport::class)->handle($importId, $e))
            ->dispatch();

        return $import;
    }
}
