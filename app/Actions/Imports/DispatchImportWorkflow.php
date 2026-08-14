<?php

namespace App\Actions\Imports;

use App\Jobs\Imports\CompleteImport;
use App\Models\Import;
use Illuminate\Support\Facades\Bus;
use Throwable;

class DispatchImportWorkflow
{
    /**
     * Dispatch an import's workflow chain to the queue.
     *
     * Used both when an import starts immediately and when a reviewed import is
     * confirmed, so the chaining and failure handling live in one place.
     */
    public function handle(Import $import): void
    {
        $importId = $import->id;

        Bus::chain([
            ...$import->type->workflow()->steps($import),
            new CompleteImport($import),
        ])
            ->catch(fn (Throwable $e) => app(FailImport::class)->handle($importId, $e))
            ->dispatch();
    }
}
