<?php

namespace App\Jobs\Imports;

use App\Events\ImportCompleted;
use App\Models\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CompleteImport implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Import $import)
    {
        //
    }

    /**
     * Finalize the import and announce its completion.
     */
    public function handle(): void
    {
        $this->import->markCompleted();

        ImportCompleted::dispatch($this->import);
    }
}
