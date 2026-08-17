<?php

use App\Console\Commands\SyncAllianceRosters;
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
 * withoutOverlapping guards against a slow API queue stacking runs on top of itself.
 */
Schedule::command(SyncAllianceRosters::class)
    ->dailyAt('03:00')
    ->withoutOverlapping();
