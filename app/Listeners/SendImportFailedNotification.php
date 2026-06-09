<?php

namespace App\Listeners;

use App\Events\ImportFailed;
use App\Notifications\Imports\ImportFailedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendImportFailedNotification implements ShouldQueue
{
    /**
     * Email the import creator that their import has failed.
     */
    public function handle(ImportFailed $event): void
    {
        $event->import->creator->notify(
            new ImportFailedNotification($event->import)
        );
    }
}
