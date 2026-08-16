<?php

namespace App\Jobs\Imports;

use App\Actions\Imports\FailImport;
use App\Enums\ImportStatus;
use App\Imports\Ocr\Contracts\VsScoreScreenshotReader;
use App\Models\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
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

    public function __construct(public Import $import) {}

    public function handle(VsScoreScreenshotReader $reader): void
    {
        try {
            $paths = array_map(
                fn (string $path): string => Storage::disk('local')->path($path),
                $this->import->payload['screenshots'] ?? [],
            );

            $rows = $reader->read($paths);

            $this->import->payload = array_merge($this->import->payload ?? [], ['rows' => $rows]);
            $this->import->status = ImportStatus::AwaitingReview;
            $this->import->save();
        } catch (Throwable $e) {
            app(FailImport::class)->handle($this->import->id, $e);
        }
    }
}
