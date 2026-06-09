<?php

namespace App\Listeners;

use App\Events\ImportCompleted;
use App\Notifications\Imports\ImportCompletedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendImportCompletedNotification implements ShouldQueue
{
    /**
     * Email the import creator that their import has completed.
     */
    public function handle(ImportCompleted $event): void
    {
        $event->import->creator->notify(
            new ImportCompletedNotification($event->import)
        );
    }
}
