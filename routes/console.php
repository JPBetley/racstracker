<?php

use App\Console\Commands\PruneImportScreenshots;
use App\Console\Commands\SyncAllianceRosters;
use App\Console\Commands\SyncGameVsScores;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Refresh every configured team's roster overnight.
 *
 * Each team's sync is queued rather than run inline, so this only ever dispatches;
 * withoutOverlapping guards against a slow API queue stacking runs on top of itself,
 * and onOneServer against every replica dispatching its own copy of the same sweep.
 */
Schedule::command(SyncAllianceRosters::class)
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

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
 * Pull this week's VS standings from the game every night at 9:30pm ET.
 *
 * That is half an hour before the server's 02:00 UTC day rollover, so each run
 * captures a nearly complete day, and Monday's run records the previous week's
 * final totals. Logging in kicks the bound account off any other device.
 */
Schedule::command(SyncGameVsScores::class)
    ->dailyAt('01:30')
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer();
