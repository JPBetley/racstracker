<?php

namespace App\Jobs\Imports;

use App\Actions\Imports\FailImport;
use App\Enums\ImportStatus;
use App\Imports\Ocr\Contracts\RosterScreenshotReader;
use App\Models\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs OCR over the uploaded roster screenshots and parks the draft for review.
 *
 * This is a pre-import step: it does not write any members. It fills the import's
 * payload with the parsed `members` draft and leaves the import awaiting review,
 * where the user corrects the draft before confirming the actual roster import.
 */
class ParseRosterScreenshots implements ShouldQueue
{
    use Queueable;

    public function __construct(public Import $import) {}

    public function handle(RosterScreenshotReader $reader): void
    {
        try {
            $paths = array_map(
                fn (string $path): string => Storage::disk('local')->path($path),
                $this->import->payload['screenshots'] ?? [],
            );

            $members = $reader->read($paths);

            $this->import->payload = array_merge($this->import->payload ?? [], ['members' => $members]);
            $this->import->status = ImportStatus::AwaitingReview;
            $this->import->save();
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
