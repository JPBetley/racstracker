<?php

namespace App\Jobs\Imports;

use App\Models\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

abstract class ImportStep implements ShouldQueue
{
    use Queueable;

    /**
     * How long a single attempt may run.
     *
     * A step that calls the Last War API can spend 30s per request, and the client
     * retries twice on top of that, so the default 60s worker timeout would kill a
     * healthy sync mid-flight.
     */
    public int $timeout = 300;

    public int $tries = 3;

    /**
     * Create a new step instance.
     */
    public function __construct(public Import $import)
    {
        //
    }

    /**
     * Execute the step, stamping the import with the current step.
     */
    public function handle(): void
    {
        $this->import->recordStep(class_basename(static::class));

        $this->run();
    }

    /**
     * Perform the step's work.
     */
    abstract protected function run(): void;

    /**
     * Merge this step's summary into the import results.
     *
     * @param  array<string, mixed>  $data
     */
    protected function record(array $data): void
    {
        $this->import->mergeResults($data);
    }
}
