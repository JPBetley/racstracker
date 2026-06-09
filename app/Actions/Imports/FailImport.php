<?php

namespace App\Actions\Imports;

use App\Events\ImportFailed;
use App\Models\Import;
use Throwable;

class FailImport
{
    /**
     * Record an import failure and announce it.
     */
    public function handle(int $importId, Throwable $e): void
    {
        $import = Import::find($importId);

        if ($import === null) {
            return;
        }

        $import->markFailed($e->getMessage());

        ImportFailed::dispatch($import);
    }
}
