<?php

use App\Console\Commands\PruneImportScreenshots;
use App\Console\Commands\SyncGameVsScores;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Sweep uploaded screenshots once OCR is long done with them.
 *
 * Nothing in the import flow deletes them, so without this the storage disk grows by
 * every screenshot ever uploaded.
 */
Schedule::command(PruneImportScreenshots::class)
    ->dailyAt('03:30')
    ->onOneServer();

/**
 * Pull each roster and this week's VS standings from the game every night at 9:30pm ET.
 *
 * That is half an hour before the server's 02:00 UTC day rollover, so each run
 * captures a nearly complete day, and Monday's run records the previous week's
 * final totals. Logging in kicks the bound account off any other device.
 *
 * One login reads both the alliance member list and the ranking, and the roster is
 * synced first so new members are matched to their scores in the same run. This
 * replaces the separate roster sweep, which remains as `import:alliance-rosters`
 * for running by hand.
 */
Schedule::command(SyncGameVsScores::class)
    ->dailyAt('01:30')
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer();
