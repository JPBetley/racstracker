<?php

namespace App\Jobs\Imports;

use App\Actions\Imports\FailImport;
use App\Enums\ImportStatus;
use App\Imports\Ocr\Contracts\VsScoreScreenshotReader;
use App\Models\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Exceptions\FailoverableException;
use Throwable;

/**
 * Runs OCR over the uploaded VS ranking screenshots and parks the draft for review.
 *
 * This is a pre-import step: it does not write any scores. It fills the import's
 * payload with the parsed `rows` draft and leaves the import awaiting review, where
 * the user matches each row to a roster member before confirming. The reviewed,
 * member-bound rows are stored separately as `scores`, so the raw OCR draft stays
 * inspectable after the fact.
 */
class ParseVsScoreScreenshots implements ShouldQueue
{
    use Queueable;

    /**
     * How long a single attempt may run.
     *
     * The vision call itself is capped by the agent's own Timeout attribute; this sits
     * above it so the worker never kills a call the agent would still have completed.
     */
    public int $timeout = 240;

    public int $tries = 3;

    /**
     * Wait before retrying, in seconds per attempt.
     *
     * Vision providers shed load under their own rate limits, which surfaces as a
     * ProviderOverloadedException on an otherwise healthy import. Backing off and
     * trying again turns a transient refusal into a slow success rather than a
     * dead-end "we could not read those screenshots".
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 90];

    public function __construct(public Import $import) {}

    public function handle(VsScoreScreenshotReader $reader): void
    {
        try {
            $rows = $reader->read($this->import->payload['screenshots'] ?? []);

            $this->import->payload = array_merge($this->import->payload ?? [], ['rows' => $rows]);
            $this->import->status = ImportStatus::AwaitingReview;
            $this->import->save();
        } catch (FailoverableException $e) {
            // A provider that is overloaded or rate limited has not told us anything
            // about these screenshots, so this is not a failed import — rethrow and let
            // the queue's backoff have another go before failed() gives up for good.
            throw $e;
        } catch (Throwable $e) {
            app(FailImport::class)->handle($this->import->id, $e);
        }
    }

    /**
     * Record the failure when the job dies outside handle()'s own try/catch.
     *
     * Dependency resolution happens before handle() runs, so a missing container
     * binding — or a timeout, or exhausted retries — would otherwise leave the import
     * stuck at "processing" behind a spinner that never resolves.
     */
    public function failed(Throwable $e): void
    {
        app(FailImport::class)->handle($this->import->id, $e);
    }
}
